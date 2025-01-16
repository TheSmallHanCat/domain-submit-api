<?php
//Mysql数据库配置
$host = ''; //数据库地址
$db   = ''; //数据库名
$user = ''; //用户名
$pass = ''; //密码
$charset = 'utf8mb4';

// 设置 DSN
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// 设置 PDO 选项
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

// 处理排序参数
$allowedColumns = ['user_id', 'total_domains', 'complete_domains', 'failed_domains']; // 允许排序的列
$sortColumn = isset($_GET['sort']) && in_array($_GET['sort'], $allowedColumns) ? $_GET['sort'] : 'complete_domains';
$sortOrder = isset($_GET['order']) && in_array($_GET['order'], ['asc', 'desc']) ? $_GET['order'] : 'desc';

// 切换排序顺序的功能
$nextOrder = $sortOrder === 'asc' ? 'desc' : 'asc';

// 统计任务总数、注册成功数和注册失败数
$totalTasksQuery = "SELECT COUNT(*) AS total_tasks FROM tasks";
$completeTasksQuery = "SELECT COUNT(*) AS complete_tasks FROM tasks WHERE status = 'complete'";
$failedTasksQuery = "SELECT COUNT(*) AS failed_tasks FROM tasks WHERE status = 'failed'";

// 执行查询
$totalTasksStmt = $pdo->query($totalTasksQuery);
$totalTasks = $totalTasksStmt->fetch()['total_tasks'];

$completeTasksStmt = $pdo->query($completeTasksQuery);
$completeTasks = $completeTasksStmt->fetch()['complete_tasks'];

$failedTasksStmt = $pdo->query($failedTasksQuery);
$failedTasks = $failedTasksStmt->fetch()['failed_tasks'];

// SQL 查询：统计每个用户的域名数量（总注册量、成功数量、失败数量），根据用户选择的列进行排序
$sql = "
    SELECT 
        user_id, 
        username, 
        COUNT(*) AS total_domains, 
        SUM(CASE WHEN status = 'complete' THEN 1 ELSE 0 END) AS complete_domains, 
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_domains
    FROM tasks 
    GROUP BY user_id, username 
    ORDER BY $sortColumn $sortOrder";

$stmt = $pdo->query($sql);
$results = $stmt->fetchAll();

// 输出HTML表格
echo "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <title>域名统计</title>
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
        .stats-table {
            margin-bottom: 20px;
        }
        .sortable {
            cursor: pointer;
            color: #007bff;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <h1>域名统计（实时）</h1>
    
    <table class='stats-table'>
        <tr>
            <th>总任务数</th>
            <th>成功数</th>
            <th>失败数</th>
        </tr>
        <tr>
            <td>{$totalTasks}</td>
            <td>{$completeTasks}</td>
            <td>{$failedTasks}</td>
        </tr>
    </table>
    
    <table>
        <tr>
            <th>用户ID</th>
            <th>用户名</th>
            <th><a class='sortable' href='?sort=total_domains&order={$nextOrder}'>总注册</a></th>
            <th><a class='sortable' href='?sort=complete_domains&order={$nextOrder}'>成功数</a></th>
            <th><a class='sortable' href='?sort=failed_domains&order={$nextOrder}'>失败数</a></th>
        </tr>";

// 遍历结果并输出到表格
foreach ($results as $row) {
    $user_id = htmlspecialchars($row['user_id'], ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
    $total_domains = htmlspecialchars($row['total_domains'], ENT_QUOTES, 'UTF-8');
    $complete_domains = htmlspecialchars($row['complete_domains'], ENT_QUOTES, 'UTF-8');
    $failed_domains = htmlspecialchars($row['failed_domains'], ENT_QUOTES, 'UTF-8');

    echo "<tr>
            <td>{$user_id}</td>
            <td>{$username}</td>
            <td>{$total_domains}</td>
            <td>{$complete_domains}</td>
            <td>{$failed_domains}</td>
          </tr>";
}

echo "</table>
</body>
</html>";
?>
