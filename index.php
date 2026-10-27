<?php
/**
 * Study Planner - PHP native + MySQL (CRUD lengkap dalam 1 file)
 * Konfigurasi database dibaca dari Environment Variables / App Settings Azure:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_SSL (true/false), APP_DEBUG (true/false)
 */
session_start();
date_default_timezone_set('Asia/Jakarta');

// ---------- Konfigurasi ----------
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'study_planner';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '';
$dbSsl  = filter_var(getenv('DB_SSL'), FILTER_VALIDATE_BOOLEAN);
$debug  = filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN);

$statusList = ['Planned', 'In Progress', 'Done'];

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ---------- Koneksi database ----------
$pdo = null;
$errorKoneksi = null;
try {
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if ($dbSsl) { // dibutuhkan oleh Azure Database for MySQL
        $opts[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
        $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser, $dbPass, $opts
    );
    // Tabel dibuat otomatis jika belum ada
    $pdo->exec("CREATE TABLE IF NOT EXISTS study_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        judul_tugas VARCHAR(150) NOT NULL,
        mata_kuliah VARCHAR(100) NOT NULL,
        tenggat DATE NOT NULL,
        status ENUM('Planned','In Progress','Done') NOT NULL DEFAULT 'Planned',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4");
} catch (PDOException $ex) {
    error_log('DB error: ' . $ex->getMessage());
    $errorKoneksi = $debug ? $ex->getMessage() : 'Tidak dapat terhubung ke database. Periksa App Settings (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD).';
}

// ---------- Proses form (Create, Update, Delete) ----------
if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['danger', 'Permintaan tidak valid.'];

    if (hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $aksi   = $_POST['aksi'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);
        $judul  = trim($_POST['judul_tugas'] ?? '');
        $matkul = trim($_POST['mata_kuliah'] ?? '');
        $tgl    = $_POST['tenggat'] ?? '';
        $status = $_POST['status'] ?? '';

        try {
            if ($aksi === 'hapus' && $id > 0) {
                $pdo->prepare("DELETE FROM study_plans WHERE id = ?")->execute([$id]);
                $flash = ['success', 'Rencana berhasil dihapus.'];

            } elseif ($aksi === 'tambah' || $aksi === 'ubah') {
                $d = DateTime::createFromFormat('Y-m-d', $tgl);
                $tglValid = $d && $d->format('Y-m-d') === $tgl;

                if ($judul === '' || $matkul === '' || !$tglValid || !in_array($status, $statusList, true)
                    || mb_strlen($judul) > 150 || mb_strlen($matkul) > 100) {
                    $flash = ['danger', 'Data tidak lengkap atau tidak valid. Periksa kembali isian Anda.'];
                } elseif ($aksi === 'tambah') {
                    $pdo->prepare("INSERT INTO study_plans (judul_tugas, mata_kuliah, tenggat, status) VALUES (?,?,?,?)")
                        ->execute([$judul, $matkul, $tgl, $status]);
                    $flash = ['success', 'Rencana berhasil ditambahkan!'];
                } elseif ($id > 0) {
                    $pdo->prepare("UPDATE study_plans SET judul_tugas=?, mata_kuliah=?, tenggat=?, status=? WHERE id=?")
                        ->execute([$judul, $matkul, $tgl, $status, $id]);
                    $flash = ['success', 'Rencana berhasil diperbarui!'];
                }
            }
        } catch (PDOException $ex) {
            error_log('DB error: ' . $ex->getMessage());
            $flash = ['danger', $debug ? $ex->getMessage() : 'Terjadi kesalahan saat menyimpan data.'];
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ---------- Ambil data (Read) ----------
$plans = [];
if ($pdo) {
    $plans = $pdo->query("SELECT * FROM study_plans ORDER BY tenggat ASC, id ASC")->fetchAll();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$hitung = array_fill_keys($statusList, 0);
foreach ($plans as $p) { $hitung[$p['status']]++; }
$hariIni = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Study Planner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">

    <div class="text-center mb-4">
        <h1 class="fw-bold">📚 Study Planner</h1>
        <p class="text-muted mb-0">Rencanakan tugas dan jadwal belajarmu dengan rapi.</p>
    </div>

    <?php if ($errorKoneksi): ?>
        <div class="alert alert-danger"><strong>Database error:</strong> <?= e($errorKoneksi) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash[0]) ?> alert-dismissible fade show" role="alert">
            <?= e($flash[1]) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Ringkasan -->
    <div class="row g-3 mb-4 text-center">
        <div class="col-4"><div class="card shadow-sm"><div class="card-body py-3">
            <div class="fs-3 fw-bold"><?= $hitung['Planned'] ?></div><small class="text-muted">Planned</small></div></div></div>
        <div class="col-4"><div class="card shadow-sm"><div class="card-body py-3">
            <div class="fs-3 fw-bold text-warning"><?= $hitung['In Progress'] ?></div><small class="text-muted">In Progress</small></div></div></div>
        <div class="col-4"><div class="card shadow-sm"><div class="card-body py-3">
            <div class="fs-3 fw-bold text-success"><?= $hitung['Done'] ?></div><small class="text-muted">Done</small></div></div></div>
    </div>

    <div class="row g-4">
        <!-- Form tambah -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white fw-semibold">Tambah Rencana</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="aksi" value="tambah">
                        <div class="mb-3">
                            <label class="form-label">Judul Tugas</label>
                            <input type="text" name="judul_tugas" class="form-control" maxlength="150" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mata Kuliah</label>
                            <input type="text" name="mata_kuliah" class="form-control" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tenggat</label>
                            <input type="date" name="tenggat" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <?php foreach ($statusList as $s): ?>
                                    <option value="<?= e($s) ?>"><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" <?= $pdo ? '' : 'disabled' ?>>Simpan</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tabel -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Daftar Rencana Belajar</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Judul Tugas</th>
                                    <th>Mata Kuliah</th>
                                    <th>Tenggat</th>
                                    <th>Status</th>
                                    <th class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($plans)): ?>
                                <?php foreach ($plans as $index => $plan): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td class="fw-medium"><?= e($plan['judul_tugas']) ?></td>
                                    <td><span class="badge bg-secondary"><?= e($plan['mata_kuliah']) ?></span></td>
                                    <td>
                                        <?= date('d M Y', strtotime($plan['tenggat'])) ?>
                                        <?php if ($plan['status'] !== 'Done' && $plan['tenggat'] < $hariIni): ?>
                                            <span class="badge bg-danger ms-1">Terlambat</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($plan['status'] === 'Done'): ?>
                                            <span class="badge bg-success">Done</span>
                                        <?php elseif ($plan['status'] === 'In Progress'): ?>
                                            <span class="badge bg-warning text-dark">In Progress</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">Planned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit"
                                            data-id="<?= (int)$plan['id'] ?>"
                                            data-judul="<?= e($plan['judul_tugas']) ?>"
                                            data-matkul="<?= e($plan['mata_kuliah']) ?>"
                                            data-tenggat="<?= e($plan['tenggat']) ?>"
                                            data-status="<?= e($plan['status']) ?>">Edit</button>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Hapus rencana ini?');">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="aksi" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Belum ada rencana belajar. Yuk tambahkan yang pertama!</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Rencana</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="aksi" value="ubah">
                <input type="hidden" name="id" id="edit-id">
                <div class="mb-3">
                    <label class="form-label">Judul Tugas</label>
                    <input type="text" name="judul_tugas" id="edit-judul" class="form-control" maxlength="150" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Mata Kuliah</label>
                    <input type="text" name="mata_kuliah" id="edit-matkul" class="form-control" maxlength="100" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tenggat</label>
                    <input type="date" name="tenggat" id="edit-tenggat" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit-status" class="form-select" required>
                        <?php foreach ($statusList as $s): ?>
                            <option value="<?= e($s) ?>"><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const modalEdit = new bootstrap.Modal(document.getElementById('modalEdit'));
    document.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit-id').value      = btn.dataset.id;
            document.getElementById('edit-judul').value   = btn.dataset.judul;
            document.getElementById('edit-matkul').value  = btn.dataset.matkul;
            document.getElementById('edit-tenggat').value = btn.dataset.tenggat;
            document.getElementById('edit-status').value  = btn.dataset.status;
            modalEdit.show();
        });
    });
</script>
</body>
</html>