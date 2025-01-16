<?php

session_start(); // 启动会话

// 检查用户是否已通过 OAuth2 认证
if (!isset($_SESSION['user_id'])) {
    // 未登录，重定向到 OAuth2 授权页面
    header('Location: /oauth2/connect.php');
    exit();
}

$trust_level=$_SESSION['user_trust_level'];

if ($trust_level <= 2) {
    echo '<p style="color:red;">LinuxDo等级不足三级</p>';
    echo '当前等级：',$trust_level;
    exit(); 
}

//Mysql数据库配置
$host = ''; //数据库地址
$db   = ''; //数据库名
$user = ''; //用户名
$pass = ''; //密码
$charset = 'utf8mb4';

$secret = ''; // 填写你的 Turnstile Secret Key


$dsn = "mysql:host=$host;dbname=$db;charset=$charset";


$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // 创建 PDO 实例
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // 连接失败，输出错误
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
function getUrlFromDomain($domain) {
    if (!preg_match('#^http(s)?://#', $domain)) {
        return 'http://' . $domain;
    }
    return $domain;
}

//验证 Cloudflare Turnstile 响应
function validate_turnstile($token) {
    $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $data = [
        'secret' => $secret,
        'response' => $token
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 10
        ]
    ];
    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === FALSE) {
        return false;
    }

    $resultJson = json_decode($result, true);
    return isset($resultJson['success']) && $resultJson['success'] === true;
}


$type = isset($_GET['type']) ? $_GET['type'] : 'form';

// 处理不同的 type 请求
switch ($type) {
    case 'submit':
        handleSubmit($pdo);
        break;
    case 'status':
        handleStatus($pdo);
        break;
    case 'get-task':
        handleGetTask($pdo);
        break;
    case 'upload':
        handleUpload($pdo);
        break;
    case 'tasks':
        handleTasks($pdo);
        break;
    case 'form':
    default:
        displayForm();
        break;
}

// 处理提交请求
function handleSubmit($pdo) {
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $domain = isset($_POST['domain']) ? trim($_POST['domain']) : '';

        $cf_turnstile_response = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';

        // 验证 Cloudflare Turnstile
        if (!validate_turnstile($cf_turnstile_response)) {
            echo json_encode(['success' => false, 'message' => 'Turnstile 验证失败']);
            exit;
        }

        // 简单验证域名格式，确保以 .me 结尾
        if (!preg_match('/^[a-zA-Z0-9-]+\.me$/', $domain)) {
            echo json_encode(['success' => false, 'message' => '无效的域名格式，支持 .me 域名']);
            exit;
        }


        try {
            // 检查域名是否已存在
            $stmt = $pdo->prepare("SELECT task_id, status, result, updated_at FROM tasks WHERE domain = ?");
            $stmt->execute([$domain]);
            $existingTask = $stmt->fetch();

            if ($existingTask) {
                // 域名已存在，返回现有的 task_id、status、result 和 updated_at
                echo json_encode([
                    'success' => true,
                    'task_id' => $existingTask['task_id'],
                    'status' => $existingTask['status'],
                    'result' => $existingTask['result'],
                    'updated_at' => $existingTask['updated_at']
                ]);
                exit();
            } else {
                // 域名不存在，插入新任务
                function generateShortTaskId() {
                    return 'task_' . bin2hex(random_bytes(4)); // 8个十六进制字符
                }

                $task_id = generateShortTaskId();
                
                $user_id = $_SESSION['user_id'];
                $username = $_SESSION['user_username'];

                $insertStmt = $pdo->prepare("INSERT INTO tasks (task_id, domain, user_id, username) VALUES (?, ?, ?, ?)");
                $insertStmt->execute([$task_id, $domain, $user_id, $username]);

                echo json_encode(['success' => true, 'task_id' => $task_id, 'status' => 'pending']);
                exit();
            }
        } catch (PDOException $e) {
            
            if ($e->getCode() == 23000) { // SQLSTATE 23000: Integrity constraint violation
                // 获取已存在的任务信息
                $stmt = $pdo->prepare("SELECT task_id, status, result FROM tasks WHERE domain = ?");
                $stmt->execute([$domain]);
                $existingTask = $stmt->fetch();

                if ($existingTask) {
                    echo json_encode([
                        'success' => true,
                        'task_id' => $existingTask['task_id'],
                        'status' => $existingTask['status'],
                        'result' => $existingTask['result']
                    ]);
                    exit();
                } else {
                    echo json_encode(['success' => false, 'message' => '未登录或其他异常']);
                    exit();
                }
            } else {
                echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
                exit();
            }
        }
    } else {
        echo json_encode(['success' => false, 'message' => '无效的请求方式']);
    }
}

// 状态查询
function handleStatus($pdo) {
    $task_id = isset($_GET['task_id']) ? $_GET['task_id'] : '';

    if (empty($task_id)) {
        echo json_encode(['success' => false, 'message' => '缺少 task_id']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT status, result, updated_at FROM tasks WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();

    if ($task) {
        echo json_encode([
            'success' => true, 
            'status' => $task['status'], 
            'result' => $task['result'],
            'updated_at' => $task['updated_at']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '未找到任务']);
    }
}

// 获取任务
function handleGetTask($pdo) {
    $passwd = isset($_GET['passwd']) ? $_GET['passwd'] : '';

    if ($passwd !== 'hancat') { // 填写请求密码，用于后端获取待处理域名
        echo "密码错误";
        exit;
    }
    // 获取一个随机的 pending 状态任务
    $stmt = $pdo->prepare("SELECT task_id, domain FROM tasks WHERE status = 'pending' ORDER BY RAND() LIMIT 1");
    $stmt->execute();
    $task = $stmt->fetch();

    if ($task) {
        // 更新状态为 in-progress
        $update = $pdo->prepare("UPDATE tasks SET status = 'in-progress' WHERE task_id = ?");
        $update->execute([$task['task_id']]);

        echo json_encode([
            'success' => true,
            'task_id' => $task['task_id'],
            'domain' => $task['domain']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '没有可用的任务']);
    }
}

// 处理上传
function handleUpload($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $task_id = isset($_POST['task_id']) ? $_POST['task_id'] : '';
        $status = isset($_POST['status']) ? $_POST['status'] : '';
        $result = isset($_POST['result']) ? $_POST['result'] : '';

        if (empty($task_id) || empty($status)) {
            echo json_encode(['success' => false, 'message' => '缺少必要参数']);
            exit;
        }

        if (!in_array($status, ['failed', 'complete'])) {
            echo json_encode(['success' => false, 'message' => '无效的状态']);
            exit;
        }

        // 更新任务
        $stmt = $pdo->prepare("UPDATE tasks SET status = ?, result = ? WHERE task_id = ?");
        try {
            $stmt->execute([$status, $result, $task_id]);
            echo json_encode(['success' => true, 'message' => '更新成功']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => '数据库更新失败']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => '无效的请求方式']);
    }
}

// 简单化用于管理员Debug查看提交记录
function handleTasks($pdo) {
    $passwd = isset($_GET['passwd']) ? $_GET['passwd'] : '';

    if ($passwd !== 'hancat') {  //在此处填写访问密码
        echo "密码错误";
        exit;
    }

    // 获取所有任务，包括 user_id 和 username
    $stmt = $pdo->prepare("SELECT * FROM tasks ORDER BY created_at DESC");
    $stmt->execute();
    $tasks = $stmt->fetchAll();

    // 显示为 HTML 表格
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset=\"UTF-8\">
        <title>所有任务</title>
        <style>
            :root {
                --main-bg-color: #f0f4f8;
                --table-bg-color: #fff;
                --font-color: #333;
                --font-family: 'Arial', sans-serif;
                --shadow-color: rgba(0, 0, 0, 0.1);
                --primary-color: #28a745;
                --primary-hover: #218838;
            }
            body {
                font-family: var(--font-family);
                background-color: var(--main-bg-color);
                padding: 20px;
            }
            h1 {
                color: var(--font-color);
                text-align: center;
                margin-bottom: 20px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                background-color: var(--table-bg-color);
                box-shadow: 0 6px 12px var(--shadow-color);
            }
            th, td {
                padding: 12px;
                border: 1px solid #ddd;
                text-align: left;
            }
            th {
                background-color: #f4f4f4;
            }
            tr:nth-child(even) {
                background-color: #f9f9f9;
            }
        </style>
    </head>
    <body>
        <h1>所有任务</h1>
        <table>
            <tr>
                <th>ID</th>
                <th>Task ID</th>
                <th>域名</th>
                <th>状态</th>
                <th>结果</th>
                <th>用户ID</th>
                <th>用户名</th>
                <th>创建时间</th>
                <th>更新时间</th>
            </tr>";

    foreach ($tasks as $task) {
        // 使用 htmlspecialchars 防止 XSS
        $id = htmlspecialchars($task['id'], ENT_QUOTES, 'UTF-8');
        $task_id = htmlspecialchars($task['task_id'], ENT_QUOTES, 'UTF-8');
        $domain = htmlspecialchars($task['domain'], ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8');
        $result = htmlspecialchars($task['result'], ENT_QUOTES, 'UTF-8');
        $user_id = htmlspecialchars($task['user_id'], ENT_QUOTES, 'UTF-8');
        $username = htmlspecialchars($task['username'], ENT_QUOTES, 'UTF-8');
        $created_at = htmlspecialchars($task['created_at'], ENT_QUOTES, 'UTF-8');
        $updated_at = htmlspecialchars($task['updated_at'], ENT_QUOTES, 'UTF-8');

        echo "<tr>
                <td>{$id}</td>
                <td>{$task_id}</td>
                <td>{$domain}</td>
                <td>{$status}</td>
                <td>{$result}</td>
                <td>{$user_id}</td>
                <td>{$username}</td>
                <td>{$created_at}</td>
                <td>{$updated_at}</td>
              </tr>";
    }

    echo "</table>
    </body>
    </html>";
}

// 提交表单
function displayForm() {
    // 获取当前用户名和ID
    $username = htmlspecialchars($_SESSION['user_username'], ENT_QUOTES, 'UTF-8');
    $user_id = $_SESSION['user_id'];
    
    // 获取用户提交的域名列表
    global $pdo;
    $stmt = $pdo->prepare("SELECT task_id, domain, status, result, created_at, updated_at FROM tasks WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $userTasks = $stmt->fetchAll();
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>域名提交</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.10.4/gsap.min.js"></script>
    <style>
        :root {
    --main-bg-color: #f0f4f8;
    --form-bg-color: #fff;
    --primary-color: #28a745;
    --primary-hover: #218838;
    --font-color: #333;
    --font-family: 'Arial', sans-serif;
    --shadow-color: rgba(0, 0, 0, 0.1);
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: var(--font-family);
    background-color: var(--main-bg-color);
    margin: 0;
    padding: 20px;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    overflow-x: hidden;
    justify-content: center;
}

.container {
    background-color: var(--form-bg-color);
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 6px 12px var(--shadow-color);
    max-width: 90%;
    width: 500px;
    opacity: 0;
    transform: translateY(20px);
    margin: 20px auto;
    overflow-wrap: break-word;
    word-wrap: break-word;
    word-break: break-word;
}

        h1 {
            color: var(--font-color);
            text-align: center;
            font-size: 2rem;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
            color: var(--font-color);
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 6px;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 18px;
            transition: background-color 0.3s ease;
        }

        button:hover {
            background-color: var(--primary-hover);
        }

        #response, #status {
            margin-top: 20px;
            color: #555;
            font-size: 14px;
            text-align: center;
        }

        .warning {
            margin-top: 15px;
            color: #d9534f;
            font-size: 14px;
            text-align: center;
        }

        .cf-turnstile {
            margin-bottom: 20px;
        }

        .result-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow-wrap: break-word;
            word-wrap: break-word;
            word-break: break-word;
        }

        .result-card .task-id {
            color: #28a745;
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .result-card .status-item {
            margin: 8px 0;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            flex-wrap: wrap;
        }

        .result-card .label {
            font-weight: 600;
            color: #495057;
            min-width: 100px;
            flex-shrink: 0;
        }

        .result-card .value {
            color: #6c757d;
            flex: 1;
            min-width: 200px;
        }

        .result-card .value a {
            color: #007bff;
            text-decoration: none;
            word-break: break-all;
            transition: color 0.2s ease;
        }

        .result-card .value a:hover {
            color: #0056b3;
            text-decoration: underline;
        }

        .result-card .warning-message {
            margin-top: 15px;
            padding: 10px;
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
            border-radius: 4px;
            word-wrap: break-word;
        }

        .result-card .success {
            color: #28a745;
        }

        .result-card .pending {
            color: #ffc107;
        }

        .result-card .failed {
            color: #dc3545;
        }

        .result-card .complete {
            color: #28a745;
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
                margin: 10px;
                width: 95%;
            }

            .result-card .status-item {
                flex-direction: column;
            }

            .result-card .label {
                min-width: auto;
                margin-bottom: 4px;
            }

            .result-card .value {
                min-width: auto;
            }
        }
        .user-tasks {
            margin-top: 30px;
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            opacity: 0;
            transform: translateY(20px);
        }
        
        .user-tasks h2 {
            color: var(--font-color);
            margin-bottom: 15px;
            font-size: 1.5rem;
            text-align: center;
        }
        
        .tasks-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .tasks-table th,
        .tasks-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .tasks-table th {
            background-color: #f4f4f4;
            font-weight: bold;
        }
        
        .tasks-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .tasks-table tr:hover {
            background-color: #f5f5f5;
        }
        
        .status-pending { color: #ffc107; }
        .status-in-progress { color: #17a2b8; }
        .status-complete { color: #28a745; }
        .status-failed { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container" id="formContainer">
        <h1>欢迎，{$username}</h1>
        <form id="submitForm" method="POST">
            <label for="domain">Domain（只支持 .me）:</label>
            <input type="text" id="domain" name="domain" placeholder="请输入 .me 域名" required pattern="^[a-zA-Z0-9-.]+$" title="只支持英文数字和连接符-，且以 .me 结尾">
            
            <!-- Turnstile widget -->
            <div class="cf-turnstile" data-sitekey="0x4AAAAAAAw70q0chMdgXhP7"></div> <!--这里修改为你自己的turnstile-sitekey-->
            <input type="hidden" name="cf-turnstile-response" id="cf-turnstile-response">

            <button type="submit">提交</button>
        </form>
        
        <p>域名不支持 xn、中文和 emoji，长度要求大于等于 3</p>
        <p>数据量大的话可能需要10-20分钟才能处理好，可以过一会再来看</p>
        <p class="warning">警告：使用本服务会记录你在LinuxDo的用户ID、昵称以及注册的域名以防止滥用</p>
        <p id="response"></p>
        <p id="status"></p>
        </div>
        <div class="user-tasks">
            <h2>提交记录</h2>
            <table class="tasks-table">
                <tr>
                    <th>域名</th>
                    <th>状态</th>
                    <th>结果</th>
                    <th>提交时间</th>
                    <th>更新时间</th>
                </tr>
HTML;
    // 输出用户任务列表
    foreach ($userTasks as $task) {
        $statusClass = 'status-' . $task['status'];
        $statusText = match($task['status']) {
            'pending' => '等待处理',
            'in-progress' => '处理中',
            'complete' => '已完成',
            'failed' => '失败',
            default => $task['status']
        };
        
        $domain = htmlspecialchars($task['domain'], ENT_QUOTES, 'UTF-8');
        $result = htmlspecialchars($task['result'], ENT_QUOTES, 'UTF-8');
        $created_at = htmlspecialchars($task['created_at'], ENT_QUOTES, 'UTF-8');
        $updated_at = htmlspecialchars($task['updated_at'], ENT_QUOTES, 'UTF-8');
        
        echo "<tr>
                <td>{$domain}</td>
                <td class='{$statusClass}'>{$statusText}</td>
                <td>" . ($result ? ($result) : '-') . "</td>
                <td>{$created_at}</td>
                <td>{$updated_at}</td>
              </tr>";
    }

    // 如果没有任务，显示提示信息
    if (empty($userTasks)) {
        echo "<tr><td colspan='5' style='text-align: center;'>暂无提交记录</td></tr>";
    }

  
    echo <<<'HTML'
            </table>
        </div>

    <script>
        window.onload = function() {
    gsap.to(".container, .user-tasks", {
        duration: 1,
        opacity: 1,
        y: 0,
        stagger: 0.2,
        ease: "power2.out"
    });
};

        document.getElementById('submitForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var domain = document.getElementById('domain').value;
            var turnstileResponse = document.getElementById('cf-turnstile-response').value || '';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/?type=submit', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        document.getElementById('response').innerHTML = '';
                        var resultHTML = '<div class="result-card">';
                        resultHTML += '<div class="task-id">任务已提交！任务ID: ' + res.task_id + '</div>';
                        
                        // 状态部分
                        resultHTML += '<div class="status-item">';
                        resultHTML += '<span class="label">状态:</span>';
                        resultHTML += '<span class="value ' + res.status + '">' + 
                            (res.status === 'pending' ? '等待处理' : 
                             res.status === 'in-progress' ? '处理中' :
                             res.status === 'complete' ? '已完成' : 
                             res.status === 'failed' ? '失败' : res.status) + '</span>';
                        resultHTML += '</div>';
                        
                        // 结果部分
                        if (res.result) {
                            resultHTML += '<div class="status-item">';
                            resultHTML += '<span class="label">结果:</span>';
                            resultHTML += '<span class="value">';
                            if (res.result.startsWith('http')) {
                                resultHTML += '<a href="' + res.result + '" target="_blank">' + res.result + '</a>';
                            } else {
                                resultHTML += res.result;
                            }
                            resultHTML += '</span></div>';
                        }
                        
                        // 时间部分
                        if (res.updated_at) {
                            var updatedAt = new Date(res.updated_at);
                            var currentTime = new Date();
                            resultHTML += '<div class="status-item">';
                            resultHTML += '<span class="label">生成时间:</span>';
                            resultHTML += '<span class="value">' + res.updated_at + '</span>';
                            resultHTML += '</div>';
                            
                            if (updatedAt < currentTime) {
                                resultHTML += '<div class="warning-message">';
                                resultHTML += '数据库存在该域名，域名已被人提交过，如果该域名仍未注册，请联系管理员修改状态';
                                resultHTML += '</div>';
                            }
                        }
                        
                        resultHTML += '</div>';
                        document.getElementById('status').innerHTML = resultHTML;
                        startPolling(res.task_id);

                        // 重置 Turnstile
                        if (typeof turnstile !== 'undefined') {
                            turnstile.reset();
                        }
                        // 清空输入框
                        document.getElementById('domain').value = '';
                        // 清空隐藏的 Turnstile 响应
                        document.getElementById('cf-turnstile-response').value = '';
                    } else {
                        document.getElementById('response').innerHTML = '<p style="color:red;">' + res.message + '</p>';
                    }
                }
            };
            // 获取 Turnstile Token 并设置到隐藏的输入字段中
            var turnstileToken = turnstile.getResponse();
            document.getElementById('cf-turnstile-response').value = turnstileToken;
            xhr.send('domain=' + encodeURIComponent(domain) + '&cf-turnstile-response=' + encodeURIComponent(turnstileToken));
        });

        function startPolling(task_id) {
            var interval = setInterval(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', '/?type=status&task_id=' + encodeURIComponent(task_id), true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            var resultHTML = '<div class="result-card">';
                            resultHTML += '<div class="task-id">任务ID: ' + task_id + '</div>';
                            
                            // 状态部分
                            resultHTML += '<div class="status-item">';
                            resultHTML += '<span class="label">状态:</span>';
                            resultHTML += '<span class="value ' + res.status + '">' + 
                                (res.status === 'pending' ? '等待处理' : 
                                 res.status === 'in-progress' ? '处理中' :
                                 res.status === 'complete' ? '已完成' : 
                                 res.status === 'failed' ? '失败' : res.status) + '</span>';
                            resultHTML += '</div>';
                            
                            // 结果部分
                            if (res.result) {
                                resultHTML += '<div class="status-item">';
                                resultHTML += '<span class="label">结果:</span>';
                                resultHTML += '<span class="value">';
                                if (res.result.startsWith('http')) {
                                    resultHTML += '<a href="' + res.result + '" target="_blank">' + res.result + '</a>';
                                } else {
                                    resultHTML += res.result;
                                }
                                resultHTML += '</span></div>';
                            }
                            
                            // 时间部分
                            if (res.updated_at) {
                                var updatedAt = new Date(res.updated_at);
                                var currentTime = new Date();
                                resultHTML += '<div class="status-item">';
                                resultHTML += '<span class="label">生成时间:</span>';
                                resultHTML += '<span class="value">' + res.updated_at + '</span>';
                                resultHTML += '</div>';
                                
                                var hoursDiff = (currentTime - updatedAt) / (1000 * 60 * 60);
                                if (hoursDiff > 1) {
                                    resultHTML += '<div class="warning-message">';
                                    resultHTML += '数据库存在该域名，说明已被提交过，如果该域名仍未注册，请联系管理员修改状态';
                                    resultHTML += '</div>';
                                }
                            }
                            
                            resultHTML += '</div>';
                            document.getElementById('status').innerHTML = resultHTML;
                            if (res.status === 'failed' || res.status === 'complete'){
                                clearInterval(interval);
                            }
                        } else {
                            document.getElementById('status').innerHTML = '<p style="color:red;">' + res.message + '</p>';
                            clearInterval(interval);
                        }
                    }
                };
                xhr.send();
            }, 3000);
        }
    </script>
</body>
</html>
HTML;
}
?>
