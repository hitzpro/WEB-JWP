# Testing dan Algoritma Sistem Manajemen Persediaan

## A. Penyebab Barang Tidak Bisa Dihapus

Pada file `master.php`, proses hapus barang menggunakan konsep soft delete, yaitu data tidak langsung dihapus dari database, tetapi nilai `is_delete` diubah menjadi `1`.

Masalahnya, pada versi lama terdapat validasi yang menolak barang untuk dihapus apabila barang tersebut sudah memiliki riwayat transaksi aktif di tabel `detail_transaksi` dan `transaksi`.

Contoh kasus pada database:
- Barang `Pulpen Joy` memiliki `id = 3`.
- Barang tersebut sudah pernah masuk ke `detail_transaksi`.
- Karena itu, saat tombol hapus ditekan, sistem menampilkan pesan gagal bahwa barang masih memiliki riwayat transaksi aktif.

Solusi yang lebih sesuai:
- Soft delete barang tetap boleh dilakukan meskipun barang memiliki riwayat transaksi.
- Hapus permanen tetap tidak boleh dilakukan apabila barang masih memiliki relasi transaksi.
- Dengan begitu, barang tidak tampil lagi di master aktif dan form transaksi, tetapi histori laporan tetap aman.

## B. List Testing Sistem

| No | Fitur | Skenario Testing | Data Uji | Hasil yang Diharapkan | Status |
|---|---|---|---|---|---|
| 1 | Login | Login dengan email dan password benar | admin@gmail.com + password benar | Masuk ke dashboard | Belum diuji |
| 2 | Login | Login dengan password salah | admin@gmail.com + password salah | Muncul pesan gagal login | Belum diuji |
| 3 | Login | Login dengan input kosong | email/password kosong | Muncul validasi wajib diisi | Belum diuji |
| 4 | Dashboard | Membuka dashboard tanpa login | akses langsung dashboard.php | Redirect ke index.php | Belum diuji |
| 5 | Dashboard | Membuka dashboard setelah login | user sudah login | Stats card dan tabel stok rendah tampil | Belum diuji |
| 6 | Master | User biasa membuka master.php | role user | Redirect ke dashboard.php | Belum diuji |
| 7 | Master Kategori | Tambah kategori baru | Nama kategori unik | Data kategori masuk ke tabel | Belum diuji |
| 8 | Master Kategori | Tambah kategori duplikat | Nama kategori sudah ada | Muncul pesan kategori sudah ada | Belum diuji |
| 9 | Master Kategori | Edit kategori | Ubah nama/deskripsi | Data kategori berhasil diperbarui | Belum diuji |
| 10 | Master Kategori | Hapus kategori yang masih punya barang aktif | kategori berelasi barang aktif | Sistem menolak hapus kategori | Belum diuji |
| 11 | Master Kategori | Hapus kategori tanpa barang aktif | kategori kosong | Kategori pindah ke sampah | Belum diuji |
| 12 | Master Barang | Tambah barang baru | Kode barang unik | Data barang masuk ke tabel barang | Belum diuji |
| 13 | Master Barang | Tambah barang dengan kode duplikat | Kode barang sudah ada | Muncul pesan kode barang sudah digunakan | Belum diuji |
| 14 | Master Barang | Edit barang | Ubah nama/stok/satuan/deskripsi | Data barang berhasil diperbarui | Belum diuji |
| 15 | Master Barang | Hapus barang yang belum punya transaksi | Barang baru tanpa transaksi | Barang pindah ke sampah | Belum diuji |
| 16 | Master Barang | Hapus barang yang sudah punya transaksi | Contoh: Pulpen Joy | Barang tetap boleh pindah ke sampah | Belum diuji |
| 17 | Master Barang | Hapus permanen barang yang punya transaksi | Barang berelasi detail_transaksi | Sistem menolak hapus permanen | Belum diuji |
| 18 | Master Pengguna | Tambah pengguna baru | Email unik | User tersimpan dengan password_hash | Belum diuji |
| 19 | Master Pengguna | Tambah pengguna email duplikat | Email sudah ada | Muncul pesan email sudah terdaftar | Belum diuji |
| 20 | Master Pengguna | Edit pengguna tanpa ubah password | Password dikosongkan | Nama/email/role berubah, password tetap | Belum diuji |
| 21 | Master Pengguna | Edit pengguna dengan password baru | Password diisi | Password_hash diperbarui | Belum diuji |
| 22 | Master Pengguna | Hapus akun sendiri | ID user login | Sistem menolak hapus akun sendiri | Belum diuji |
| 23 | Barang Masuk | Input barang masuk valid | Qty > 0 | Transaksi tersimpan dan stok bertambah | Belum diuji |
| 24 | Barang Masuk | Input qty 0 | Qty = 0 | Muncul validasi error | Belum diuji |
| 25 | Barang Keluar | Input barang keluar valid | Qty <= stok | Transaksi tersimpan dan stok berkurang | Belum diuji |
| 26 | Barang Keluar | Input qty melebihi stok | Qty > stok | Muncul pesan stok tidak mencukupi | Belum diuji |
| 27 | Laporan | Filter tanggal bulan berjalan | Tanggal awal/akhir default | Data transaksi bulan berjalan tampil | Belum diuji |
| 28 | Laporan | Filter kategori | Pilih kategori tertentu | Data hanya kategori tersebut | Belum diuji |
| 29 | Laporan | Filter keyword barang | Masukkan nama barang | Data sesuai keyword tampil | Belum diuji |
| 30 | Forgot Password | Cek email terdaftar | Email valid | Form password baru tampil | Belum diuji |
| 31 | Forgot Password | Cek email tidak terdaftar | Email tidak ada | Muncul pesan email tidak ditemukan | Belum diuji |
| 32 | Forgot Password | Password baru tidak sama | Password dan konfirmasi beda | Muncul pesan error | Belum diuji |
| 33 | Forgot Password | Password baru valid | Password minimal 6 karakter | Password_hash diperbarui | Belum diuji |
| 34 | Logout | Klik logout | User login | Session dihapus dan kembali ke index.php | Belum diuji |

## C. Algoritma Dashboard

### Tujuan
Menampilkan ringkasan informasi persediaan barang, jumlah kategori, jumlah barang, total barang masuk, total barang keluar, serta daftar barang yang stoknya berada di bawah batas minimum.

### Algoritma
1. Sistem memulai halaman dashboard.
2. Sistem menjalankan session.
3. Sistem memeriksa apakah pengguna sudah login.
4. Jika pengguna belum login, sistem mengarahkan pengguna ke halaman login.
5. Jika pengguna sudah login, sistem mengambil data session pengguna.
6. Sistem menghitung total kategori barang aktif dari tabel `kategori_barang` dengan kondisi `is_delete = 0`.
7. Sistem menghitung total barang aktif dari tabel `barang` dengan kondisi `is_delete = 0`.
8. Sistem menghitung total barang masuk pada bulan berjalan dari tabel `transaksi` dan `detail_transaksi`.
9. Sistem menghitung total barang keluar pada bulan berjalan dari tabel `transaksi` dan `detail_transaksi`.
10. Sistem menentukan batas minimum stok, yaitu kurang dari 10.
11. Sistem menghitung jumlah barang aktif yang stoknya berada di bawah batas minimum.
12. Sistem mengambil daftar barang aktif yang stoknya kurang dari 10.
13. Sistem mengurutkan daftar stok rendah dari stok terkecil.
14. Sistem menampilkan data ringkasan ke dalam stats card.
15. Sistem menampilkan daftar barang stok rendah ke dalam tabel.
16. Sistem menampilkan halaman dashboard kepada pengguna.
17. Selesai.

## D. Algoritma Master Data

### Tujuan
Mengelola data master seperti kategori barang, barang, dan pengguna. Halaman ini hanya dapat diakses oleh admin.

### Algoritma
1. Sistem memulai halaman master data.
2. Sistem menjalankan session.
3. Sistem memeriksa apakah pengguna sudah login.
4. Jika pengguna belum login, sistem mengarahkan pengguna ke halaman login.
5. Sistem memeriksa role pengguna.
6. Jika role pengguna bukan admin, sistem mengarahkan pengguna ke halaman dashboard.
7. Jika role pengguna adalah admin, sistem mengambil data session admin.
8. Sistem menentukan mode tampilan data, yaitu data aktif atau data sampah.
9. Sistem menentukan tab aktif, yaitu kategori, barang, atau pengguna.
10. Sistem memeriksa apakah ada request POST dari form master data.
11. Jika admin menambah kategori, sistem memvalidasi nama kategori.
12. Jika nama kategori belum digunakan, sistem menyimpan kategori baru ke tabel `kategori_barang`.
13. Jika admin menambah barang, sistem memvalidasi kode barang.
14. Jika kode barang belum digunakan, sistem menyimpan barang baru ke tabel `barang`.
15. Jika admin menambah pengguna, sistem memvalidasi email pengguna.
16. Jika email belum terdaftar, sistem melakukan hashing password dan menyimpan pengguna ke tabel `users`.
17. Jika admin mengedit kategori, sistem memperbarui data kategori berdasarkan ID.
18. Jika admin mengedit barang, sistem memperbarui data barang berdasarkan ID.
19. Jika admin mengedit pengguna, sistem memperbarui nama, email, role, dan password jika password baru diisi.
20. Jika admin menghapus kategori, sistem memeriksa apakah kategori masih memiliki barang aktif.
21. Jika kategori masih memiliki barang aktif, sistem menolak proses hapus kategori.
22. Jika kategori valid untuk dihapus, sistem mengubah `is_delete` kategori menjadi `1`.
23. Jika admin menghapus barang, sistem mengubah `is_delete` barang menjadi `1`.
24. Barang yang sudah memiliki transaksi tetap boleh dipindahkan ke sampah karena proses ini hanya soft delete.
25. Jika admin menghapus pengguna, sistem memeriksa apakah pengguna adalah akun yang sedang login.
26. Jika pengguna adalah akun yang sedang login, sistem menolak proses hapus.
27. Jika pengguna masih memiliki transaksi aktif, sistem menolak proses hapus.
28. Jika pengguna valid untuk dihapus, sistem mengubah `is_delete` pengguna menjadi `1`.
29. Jika admin membuka menu sampah, sistem menampilkan data dengan `is_delete = 1`.
30. Jika admin melakukan restore kategori, sistem mengubah `is_delete` kategori menjadi `0`.
31. Jika admin melakukan restore barang, sistem memeriksa apakah kategori dari barang tersebut masih berada di sampah.
32. Jika kategori masih berada di sampah, sistem menolak restore barang.
33. Jika valid, sistem mengubah `is_delete` barang menjadi `0`.
34. Jika admin melakukan restore pengguna, sistem mengubah `is_delete` pengguna menjadi `0`.
35. Jika admin menghapus permanen kategori, sistem memeriksa apakah kategori masih memiliki barang.
36. Jika kategori masih memiliki barang, sistem menolak hapus permanen.
37. Jika admin menghapus permanen barang, sistem memeriksa apakah barang masih memiliki detail transaksi.
38. Jika barang masih memiliki detail transaksi, sistem menolak hapus permanen.
39. Jika admin menghapus permanen pengguna, sistem memeriksa apakah pengguna masih memiliki transaksi.
40. Jika pengguna masih memiliki transaksi, sistem menolak hapus permanen.
41. Sistem mengambil data kategori, barang, dan pengguna sesuai mode tampilan dan paginasi.
42. Sistem menampilkan data ke tabel sesuai tab aktif.
43. Sistem menampilkan pesan berhasil atau gagal sesuai proses yang dilakukan.
44. Selesai.

## E. Algoritma Barang Masuk

### Tujuan
Mencatat transaksi barang masuk dan menambahkan stok barang di sistem persediaan.

### Algoritma
1. Sistem memulai halaman form barang.
2. Sistem menjalankan session.
3. Sistem memeriksa apakah pengguna sudah login.
4. Jika pengguna belum login, sistem mengarahkan pengguna ke halaman login.
5. Sistem mengambil data session pengguna.
6. Sistem membaca parameter `jenis` dari URL.
7. Jika parameter `jenis` bernilai `masuk`, sistem menampilkan form barang masuk.
8. Sistem mengambil daftar barang aktif dari database.
9. Pengguna memilih barang yang akan ditambahkan stoknya.
10. Pengguna mengisi jumlah barang, tanggal transaksi, dan keterangan.
11. Sistem memvalidasi jenis transaksi, barang, jumlah barang, dan format tanggal.
12. Jika input tidak valid, sistem menampilkan pesan error.
13. Jika input valid, sistem memulai database transaction.
14. Sistem mengambil stok barang saat ini dan mengunci baris barang menggunakan `FOR UPDATE`.
15. Sistem menyimpan nilai stok sebelum transaksi.
16. Sistem menghitung stok sesudah transaksi dengan rumus stok lama ditambah jumlah barang masuk.
17. Sistem membuat nomor transaksi unik.
18. Sistem menyimpan data transaksi ke tabel `transaksi`.
19. Sistem menyimpan detail transaksi ke tabel `detail_transaksi`.
20. Sistem memperbarui stok barang pada tabel `barang`.
21. Jika semua proses berhasil, sistem melakukan commit transaction.
22. Sistem menampilkan pesan bahwa transaksi barang masuk berhasil disimpan.
23. Jika terjadi kesalahan, sistem melakukan rollback transaction.
24. Sistem menampilkan pesan error.
25. Selesai.

## F. Algoritma Barang Keluar

### Tujuan
Mencatat transaksi barang keluar dan mengurangi stok barang di sistem persediaan.

### Algoritma
1. Sistem memulai halaman form barang.
2. Sistem menjalankan session.
3. Sistem memeriksa apakah pengguna sudah login.
4. Jika pengguna belum login, sistem mengarahkan pengguna ke halaman login.
5. Sistem mengambil data session pengguna.
6. Sistem membaca parameter `jenis` dari URL.
7. Jika parameter `jenis` bernilai `keluar`, sistem menampilkan form barang keluar.
8. Sistem mengambil daftar barang aktif dari database.
9. Pengguna memilih barang yang akan dikeluarkan.
10. Pengguna mengisi jumlah barang, tanggal transaksi, dan keterangan.
11. Sistem memvalidasi jenis transaksi, barang, jumlah barang, dan format tanggal.
12. Jika input tidak valid, sistem menampilkan pesan error.
13. Jika input valid, sistem memulai database transaction.
14. Sistem mengambil stok barang saat ini dan mengunci baris barang menggunakan `FOR UPDATE`.
15. Sistem memeriksa apakah jumlah barang keluar melebihi stok tersedia.
16. Jika jumlah barang keluar lebih besar dari stok, sistem membatalkan proses dan menampilkan pesan stok tidak mencukupi.
17. Jika stok mencukupi, sistem menyimpan nilai stok sebelum transaksi.
18. Sistem menghitung stok sesudah transaksi dengan rumus stok lama dikurangi jumlah barang keluar.
19. Sistem membuat nomor transaksi unik.
20. Sistem menyimpan data transaksi ke tabel `transaksi`.
21. Sistem menyimpan detail transaksi ke tabel `detail_transaksi`.
22. Sistem memperbarui stok barang pada tabel `barang`.
23. Jika semua proses berhasil, sistem melakukan commit transaction.
24. Sistem menampilkan pesan bahwa transaksi barang keluar berhasil disimpan.
25. Jika terjadi kesalahan, sistem melakukan rollback transaction.
26. Sistem menampilkan pesan error.
27. Selesai.

## G. Algoritma Laporan

### Tujuan
Menampilkan laporan transaksi persediaan barang berdasarkan filter tanggal, kategori, dan kata kunci barang.

### Algoritma
1. Sistem memulai halaman laporan.
2. Sistem menjalankan session.
3. Sistem memeriksa apakah pengguna sudah login.
4. Jika pengguna belum login, sistem mengarahkan pengguna ke halaman login.
5. Sistem mengambil data session pengguna.
6. Sistem menentukan tanggal awal filter.
7. Jika tanggal awal tidak diisi, sistem menggunakan tanggal awal bulan berjalan.
8. Sistem menentukan tanggal akhir filter.
9. Jika tanggal akhir tidak diisi, sistem menggunakan tanggal akhir bulan berjalan.
10. Sistem mengambil filter kategori jika dipilih pengguna.
11. Sistem mengambil kata kunci pencarian jika diisi pengguna.
12. Sistem mengambil daftar kategori aktif untuk pilihan filter.
13. Sistem membuat query laporan dari tabel `transaksi`, `detail_transaksi`, `barang`, dan `kategori_barang`.
14. Sistem menambahkan kondisi tanggal berdasarkan tanggal awal dan tanggal akhir.
15. Jika kategori dipilih, sistem menambahkan filter kategori ke query.
16. Jika kata kunci diisi, sistem menambahkan filter nama barang ke query.
17. Sistem mengurutkan data transaksi dari tanggal terbaru.
18. Sistem menjalankan query menggunakan prepared statement.
19. Sistem mengambil hasil laporan dari database.
20. Sistem menghitung total data laporan.
21. Sistem menghitung total barang masuk.
22. Sistem menghitung total barang keluar.
23. Sistem menampilkan hasil laporan ke dalam tabel.
24. Sistem menampilkan ringkasan total data, total barang masuk, dan total barang keluar.
25. Sistem menampilkan catatan laporan pada baris terpisah.
26. Jika tombol cetak ditekan, sistem menjalankan fungsi cetak browser.
27. Selesai.

## H. Algoritma Forgot Password

### Tujuan
Memberikan fitur penggantian password sederhana berdasarkan email pengguna yang sudah terdaftar di database.

### Algoritma
1. Sistem memulai halaman forgot password.
2. Sistem menjalankan session.
3. Sistem menampilkan form input email.
4. Pengguna memasukkan email yang terdaftar.
5. Sistem memeriksa apakah email ada di tabel `users` dan tidak berada di sampah.
6. Jika email tidak ditemukan, sistem menampilkan pesan email tidak ditemukan.
7. Jika email ditemukan, sistem menampilkan form password baru.
8. Pengguna memasukkan password baru dan konfirmasi password.
9. Sistem memvalidasi password baru dan konfirmasi password.
10. Jika password kosong, sistem menampilkan pesan error.
11. Jika konfirmasi password tidak sama, sistem menampilkan pesan error.
12. Jika password kurang dari 6 karakter, sistem menampilkan pesan error.
13. Jika password valid, sistem melakukan hashing password.
14. Sistem memperbarui kolom `password_hash` pada tabel `users` berdasarkan email.
15. Jika update berhasil, sistem menampilkan pesan password berhasil diubah.
16. Sistem menyediakan tombol kembali ke halaman login.
17. Selesai.

## I. Algoritma Logout

### Tujuan
Menghapus session pengguna dan mengarahkan pengguna kembali ke halaman login.

### Algoritma
1. Sistem memulai proses logout.
2. Sistem menjalankan session.
3. Sistem menghapus seluruh data session pengguna.
4. Sistem memeriksa apakah session menggunakan cookie.
5. Jika session menggunakan cookie, sistem menghapus cookie session.
6. Sistem menghancurkan session.
7. Sistem mengarahkan pengguna ke halaman `index.php`.
8. Selesai.
