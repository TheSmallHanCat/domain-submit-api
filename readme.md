# 基于 PHP 实现的域名提交程序 ✨

本项目使用了 **[Linux.do OAuth 2.0 SDK](https://linux.do/t/topic/191534?u=thesmallhancat)**

## 配置说明

1. 请注意修改根目录下的 `index.php` 文件中 Mysql 数据库配置 (位于 `20` 行)、Turnstile 密钥 (位于 `27` 和 `654` 行) 和管理员访问密码 (`270` 行)。
2. 修改 `status` 文件夹内的文件中的 Mysql 数据库配置。
3. 根目录index提供域名提交页面，status内index提供域名注册统计页面

## API 接口文档 📑

用于处理域名提交、状态查询、任务获取及状态更新等操作。

### 1. 处理域名提交请求 🚀

-   **接口地址:** `/?type=submit`
-   **请求类型:** `POST`
-   **表单数据:**

    | 参数   | 必填 | 说明         |
    | ------ | ---- | ------------ |
    | domain | 是   | 待提交的域名 |

    示例: `domain=hancat.me`

-   **响应:**

    ```json
    {
      "success": true,
      "task_id": "task_xxxxx",
      "status": "pending"
    }
    ```

### 2. 查询域名状态 🔍

-   **接口地址:** `/?type=status&task_id=任务ID`
-   **请求类型:** `GET`
-   **响应:**

    ```json
    {
      "success": true,
      "status": "pending",
      "result": "",
      "updated_at": "2025-01-16 13:37:50"
    }
    ```

### 3. 获取待处理域名 🎯

-   **接口地址:** `/?type=get-task`
-   **请求类型:** `GET`
-   **响应:**

    ```json
    {
      "success": true,
      "task_id": "task_xxxx",
      "domain": "xxxx"
    }
    ```

    > 每次请求随机返回一个 `status` 为 `pending` 的域名，并将其 `status` 更新为 `in-progress`。

### 4. 更新域名状态 🔄

-   **接口地址:** `/?type=upload&task_id={}`
-   **请求类型:** `POST`
-   **表单数据:**

    -   成功:

        ```json
        {
          "task_id": "xxxx",
          "status": "complete",
          "result": "激活链接"
        }
        ```

    -   失败:

        ```json
        {
          "task_id": "xxxx",
          "status": "failed",
          "result": "失败原因"
        }
        ```

    > 用于后端请求更新数据库中域名的状态，状态和结果会返回给用户 

### 5. 任务列表 📋

-   **接口地址:** `/?type=tasks&passwd=访问密码`
-   **请求类型:** `GET`
-   **响应:**  `HTML` 页面 (展示任务列表)

    > 仅用于管理员Debug查看域名提交记录，直接在浏览器打开。

---

**备注:**

-   `task_id` 为任务的唯一标识符。
-   `status` 字段表示任务状态，取值范围：`pending` (等待处理), `in-progress` (处理中), `complete` (完成), `failed` (失败)。
    -   `pending`: 域名已提交，等待处理。
    -   `in-progress`: 域名正在处理中。
    -   `complete`: 域名处理完成, `result`字段存放激活链接。
    -   `failed`: 域名处理失败, `result`字段存放失败原因。
-   `result` 字段在任务完成时存放激活链接，失败时存放失败原因。
-   `updated_at` 字段表示任务状态的最后更新时间。


我的主页 **[TheSmallHanCat](https://linux.do/u/thesmallhancat/)**
