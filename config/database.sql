-- =========================
-- BUAT DATABASE
-- =========================
CREATE DATABASE IF NOT EXISTS lsp_inventory;

USE lsp_inventory;


-- =========================
-- TABLE USERS
-- =========================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    is_delete BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =========================
-- TABLE KATEGORI BARANG
-- =========================
CREATE TABLE kategori_barang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_kategori VARCHAR(100) NOT NULL,
    deskripsi TEXT,
    is_delete BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- =========================
-- TABLE BARANG
-- =========================
CREATE TABLE barang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_kategori INT NOT NULL,
    kode_barang VARCHAR(50) NOT NULL UNIQUE,
    nama_barang VARCHAR(150) NOT NULL,
    deskripsi TEXT,
    stok INT DEFAULT 0,
    satuan VARCHAR(50),
    is_delete BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_barang_kategori
        FOREIGN KEY (id_kategori)
        REFERENCES kategori_barang(id)
);


-- =========================
-- TABLE TRANSAKSI
-- =========================
CREATE TABLE transaksi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    no_transaksi VARCHAR(50) NOT NULL UNIQUE,
    jenis_transaksi VARCHAR(20) NOT NULL,
    tanggal_transaksi DATE DEFAULT (CURRENT_DATE),
    id_user INT NOT NULL,
    keterangan TEXT,
    status VARCHAR(20) DEFAULT 'selesai',
    is_delete BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_transaksi_user
        FOREIGN KEY (id_user)
        REFERENCES users(id)
);


-- =========================
-- TABLE DETAIL TRANSAKSI
-- =========================
CREATE TABLE detail_transaksi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_transaksi INT NOT NULL,
    id_barang INT NOT NULL,
    qty INT NOT NULL,
    stok_sebelum INT DEFAULT 0,
    stok_sesudah INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_detail_transaksi
        FOREIGN KEY (id_transaksi)
        REFERENCES transaksi(id),

    CONSTRAINT fk_detail_barang
        FOREIGN KEY (id_barang)
        REFERENCES barang(id)
);
