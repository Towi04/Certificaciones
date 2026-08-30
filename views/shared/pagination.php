<?php
/** @var array{page:int,per_page:int,per_page_param:string,total:int,total_pages:int,offset:int,limit:?int} $pagination */
/** @var string|null $basePath */
use App\Support\Pagination;

if (!isset($pagination)) {
    return;
}
$basePath = $basePath ?? parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$queryBase = Pagination::queryString();
$from = $pagination['total'] === 0 ? 0 : ($pagination['offset'] + 1);
$to = min($pagination['offset'] + $pagination['per_page'], $pagination['total']);
?>
<div class="pagination-bar">
    <div class="pagination-meta muted">
        <?= $pagination['total'] === 0 ? 'Sin registros' : "Mostrando {$from}–{$to} de {$pagination['total']}" ?>
    </div>
    <div class="pagination-controls">
        <form method="get" class="pagination-per-page">
            <?php foreach ($_GET as $key => $value): ?>
                <?php if ($key === 'per_page' || $key === 'page') {
                    continue;
                } ?>
                <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
            <label>
                Filas
                <select name="per_page" onchange="this.form.submit()">
                    <?php foreach (['25' => '25', '50' => '50', '100' => '100', 'all' => 'Todas'] as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= ($pagination['per_page_param'] ?? '25') === $val ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php if ($pagination['total_pages'] > 1): ?>
            <nav class="pagination-nav" aria-label="Paginación">
                <?php
                $prev = max(1, $pagination['page'] - 1);
                $next = min($pagination['total_pages'], $pagination['page'] + 1);
                $prevQs = $queryBase !== '' ? $queryBase . '&' : '';
                $prevQs .= 'page=' . $prev . '&per_page=' . urlencode($pagination['per_page_param']);
                $nextQs = $queryBase !== '' ? $queryBase . '&' : '';
                $nextQs .= 'page=' . $next . '&per_page=' . urlencode($pagination['per_page_param']);
                ?>
                <a class="btn btn-ghost btn-sm <?= $pagination['page'] <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(url($basePath . '?' . $prevQs)) ?>"
                   aria-disabled="<?= $pagination['page'] <= 1 ? 'true' : 'false' ?>">← Anterior</a>
                <span class="pagination-pages muted">Página <?= (int) $pagination['page'] ?> / <?= (int) $pagination['total_pages'] ?></span>
                <a class="btn btn-ghost btn-sm <?= $pagination['page'] >= $pagination['total_pages'] ? 'disabled' : '' ?>"
                   href="<?= e(url($basePath . '?' . $nextQs)) ?>"
                   aria-disabled="<?= $pagination['page'] >= $pagination['total_pages'] ? 'true' : 'false' ?>">Siguiente →</a>
            </nav>
        <?php endif; ?>
    </div>
</div>
