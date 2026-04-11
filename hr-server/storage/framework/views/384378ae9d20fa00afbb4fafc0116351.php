<li>
    <div class="tree-node">
        <div><span class="dept-name"><?php echo e($node->name); ?></span><span class="dept-code"><?php echo e($node->code); ?></span></div>
        <span class="dept-count"><i class="fas fa-user"></i> <?php echo e($node->employees_count ?? 0); ?>명</span>
    </div>
    <?php if($node->childrenRecursive && $node->childrenRecursive->count()): ?>
        <ul><?php $__currentLoopData = $node->childrenRecursive; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $child): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php echo $__env->make('departments._tree_node', ['node' => $child], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></ul>
    <?php endif; ?>
</li>
<?php /**PATH A:\risk_server\project\hr-server\resources\views/departments/_tree_node.blade.php ENDPATH**/ ?>