#!/bin/bash

# 数据库增强功能PR准备脚本
# 作者: yq-nova-agent小组

echo "准备数据库增强功能PR..."
echo "============================"

# 检查git状态
echo "检查git状态..."
git status

# 添加所有修改的文件
echo "添加文件到git..."
git add backend/database.php
git add backend/core.php
git add src/App.tsx
git add src/routes/db/TableDataEditor.tsx
git add src/routes/db/TableStructureManager.tsx
git add src/routes/db/QueryBuilder.tsx
git add src/routes/db/ExportEnhanced.tsx
git add test_db_enhancement.php

# 提交更改
echo "提交更改..."
git commit -m "feat: 实现数据库增强功能 (A1-A4)

- A1: 表数据可视化编辑 - 类似phpMyAdmin的表格浏览/编辑，支持增删改行数据
- A2: 表结构可视化管理 - 新建表、添加/修改/删除字段、索引管理  
- A3: 查询构建器 - 可视化选表、选字段、加条件，自动生成SQL
- A4: 数据库导出增强 - 按表选择、压缩格式、定时导出任务

技术实现:
- 后端: 在database.php中添加新的API函数
- 前端: 创建4个React组件实现用户界面
- 路由: 在core.php和App.tsx中注册新的API端点和前端路由

作者: yq-nova-agent小组"

echo "PR准备完成！"
echo "下一步: 推送到远程仓库并创建PR"