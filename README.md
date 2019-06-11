# ocipdo
基于 OCI API 封装的 Oracle PDO 数据库驱动

## 说明

由于官方很久没有对 PDO_OCI 更新了，其驱动源码默认不支持 Oracle 11g 及以上版本的数据库。当然也可在编译前修改 config.m4 文件使之支持，但安装之后，PDO_OCI 使用中却存在问题，如果数据库中存储中文，查询后会出现字符截断，无法得到预期的结果。

本库使用基于 OCI API 封装的 PDO 接口数据库驱动 [misuoka\ocipdo](https://github.com/misuoka/ocipdo)，用来对 Oracle 数据库进行操作。

> 有关 PDO_OCI 字符截断问题的链接：https://my.oschina.net/startphp/blog/195333

## 使用方法

### 使用前提

你的运行环境已经安装了 oci 驱动

> 安装方法可参考：
> 1. Windows 环境下 https://blog.csdn.net/luodong1983/article/details/79986180
> 2. Linux 环境下 https://gist.github.com/hewerthomn/81eea2935051eb2500941a9309bca703

### 安装 ocipdo

使用 composer 进行安装 `composer require misuoka/ocipdo`

```PHP
use ocipdo\PDO as OCIPDO;

// $dns => oci:dbname=//localhost:1521/orcl
// $username => 用户名
// $password => 密码
$pdo = new OCIPDO($dns, $username, $password);
```

## 注意事项

由于 Oracle 数据库中有 CLOB 和 BLOB 字段，CLOB 是存储文本大对象，BLOB 是存储二进制大对象，在 OCI 中二者对应的数据类型不一样：

- 使用 `oci_bind_by_name` 绑定 `CLOB` 数据时，需要设置的类型为 `SQLT_CLOB`。
- 使用 `oci_bind_by_name` 绑定 `BLOB` 数据时，需要设置的类型为 `SQLT_BLOB`。

而 PDO 的预定义常量中，只有 `PDO::PARAM_LOB` 是对大对象数据的类型定义，无法区分用户要写入的数据字段是 `CLOB` 还是 `BLOB`，故本驱动 [misuoka\ocipdo](https://github.com/misuoka/ocipdo) 进行了如下设定：

- `PDO::PARAM_LOB` 对应于要写入的是 `CLOB` 数据
- `PDO::PARAM_LOB + PDO::PARAM_LOB` 对应于要写入的是 `BLOB` 数据

```PHP
$ret = $stmt->bindValue(':CLOB_CONTENT', $data['CLOB_CONTENT'], \PDO::PARAM_LOB); // 写入 CLOB 数据
$ret = $stmt->bindValue(':BLOB_CONTENT', $data['BLOB_CONTENT'], \PDO::PARAM_LOB + \PDO::PARAM_LOB); // 写入 BLOB 数据
```

