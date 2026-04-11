<li>
    <div class="tree-node">
        <div><span class="dept-name">{{ $node->name }}</span><span class="dept-code">{{ $node->code }}</span></div>
        <span class="dept-count"><i class="fas fa-user"></i> {{ $node->employees_count ?? 0 }}명</span>
    </div>
    @if($node->childrenRecursive && $node->childrenRecursive->count())
        <ul>@foreach($node->childrenRecursive as $child)@include('departments._tree_node', ['node' => $child])@endforeach</ul>
    @endif
</li>
