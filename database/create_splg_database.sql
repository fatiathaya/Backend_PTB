-- Script untuk membuat database splg
-- Jalankan script ini di MySQL/MariaDB

CREATE DATABASE IF NOT EXISTS splg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE splg;

-- Tabel users akan dibuat melalui Laravel migration
-- Jalankan: php artisan migrate

