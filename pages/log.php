<?php
// Protezione sessione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Recupera il log di navigazione dalla sessione
$navigation_log = $_SESSION['navigation_log'] ?? [];

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3><i class="bi bi-journal-text me-2 text-info"></i> Log di Navigazione</h3>
        <p class="text-muted mb-0">Visualizza la cronologia delle pagine visitate.</p>
    </div>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Home</a></li>
            <li class="breadcrumb-item active">Log di Navigazione</li>
        </ol>
    </nav>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white py-3">
        <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Cronologia</h6>
    </div>
    <div class="card-body">
        <?php if (empty($navigation_log)): ?>
            <div class="alert alert-info mb-0">Nessun evento di navigazione registrato.</div>
        <?php else: ?>
            <ul class="list-group">
                <?php foreach (array_reverse($navigation_log) as $entry): // Mostra gli elementi più recenti per primi ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold"><?= htmlspecialchars($entry['page']) ?></div>
                            <small class="text-muted"><?= htmlspecialchars($entry['timestamp']) ?></small>
                        </div>
                        <?php if (isset($entry['params'])): ?>
                            <span class="badge bg-secondary rounded-pill">Params: <?= htmlspecialchars($entry['params']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>