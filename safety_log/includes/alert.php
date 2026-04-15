<?php if (!empty($alertMessage)): ?>
    <div class="alert <?= h($alertClass ?? 'alert-info') ?> alert-dismissible fade show mb-4" role="alert">
        <?= h($alertMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
    </div>
<?php endif; ?>
