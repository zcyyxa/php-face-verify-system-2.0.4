# PHP人脸实名分发系统 2.0.4

基于 ThinkPHP 8 + Vue 3。

## 环境

- PHP 8.1+，需要 pdo_mysql、curl、mbstring、openssl、fileinfo、zip、gd
- MySQL 5.7+
- Nginx 或 Apache

## 安装

1. 上传到网站根目录，站点根指向 `public/`
2. 访问 `/install`，按向导完成环境检测、数据库和站点配置
3. 安装后 `install/` 不可访问
4. 站点根 `/` 登录后台

## 伪静态

Nginx：

```nginx
location / {
    try_files $uri $uri/ /index.php?s=$uri&$args;
}
```

Apache 见 `public/.htaccess`。

## 活体插件

| 插件 | 购买地址 |
|---|---|
| 阿里云市场 · 活体 H5 | https://market.aliyun.com/detail/cmapi00071987 |
| 阿里云市场 · 神度活体 H5 | https://market.aliyun.com/detail/cmapi00072156 |
| 阿里云市场 · 易顺达活体 H5 | https://market.aliyun.com/detail/cmapi00071079 |
| 阿里云市场 · 安那其炫光活体 | https://market.aliyun.com/detail/cmapi00064004 |

## 比对插件

| 插件 | 购买地址 |
|---|---|
| 阿里云市场 · 人脸身份证比对 | https://market.aliyun.com/detail/cmapi00069802 |
| 阿里云市场 · 蓝笛人脸实名 | https://market.aliyun.com/detail/cmapi00060795 |
| 阿里云市场 · 新诺韦尔人脸三要素 | https://market.aliyun.com/detail/cmapi00049446 |
| 阿里云市场 · 新诺韦尔人脸比对三要素 | https://market.aliyun.com/detail/cmapi00051762 |
| 阿里云市场 · 安那其实人认证 | https://market.aliyun.com/detail/cmapi00046900 |
| 阿里云市场 · 数脉人像比对 | https://market.aliyun.com/detail/cmapi033789 |
| 阿里云市场 · 数链云人脸三要素 | https://market.aliyun.com/detail/cmapi00050254 |
| 阿里云市场 · 怜花人脸三要素 | https://market.aliyun.com/detail/cmapi00061263 |
| 阿里云市场 · 快证签人像比对 | https://market.aliyun.com/detail/cmapi00066582 |
| 阿里云市场 · 互联三六五实人认证 | https://market.aliyun.com/detail/cmapi00037888 |

## 交流群

https://qm.qq.com/q/omfCmcscTu

## 版本

2.0.4