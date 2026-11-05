-- Jalankan sekali sebelum menggunakan portal wali santri.
ALTER TABLE users MODIFY level ENUM('admin','bendahara','kepala_tpq','wali_santri') NOT NULL DEFAULT 'bendahara';
ALTER TABLE iuran
    ADD jatuh_tempo_hari TINYINT UNSIGNED NOT NULL DEFAULT 10,
    ADD jatuh_tempo_bulan TINYINT UNSIGNED NOT NULL DEFAULT 7;
CREATE TABLE wali_santri (
    id_user INT(11) NOT NULL,
    id_santri INT(11) NOT NULL,
    PRIMARY KEY (id_user, id_santri),
    KEY idx_wali_santri (id_santri),
    CONSTRAINT fk_wali_user FOREIGN KEY (id_user) REFERENCES users(id_user) ON DELETE CASCADE,
    CONSTRAINT fk_wali_anak FOREIGN KEY (id_santri) REFERENCES santri(id_santri) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
