<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Reactive;

new class extends Component {

    #[Reactive]
    public string $title = 'Page Title';

    #[Reactive]
    public array $items = [];

    public function mount(string $title = 'Page Title', array $items = []): void
    {

        $this->title = $title;
        $this->items = $items;
    }

}; ?>

<div class="card card-body py-3">
    <div class="row align-items-center">
        <div class="col-12">
            <div class="d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-3 mb-sm-0 card-title">{{ $title }}</h4>
                <nav aria-label="breadcrumb" class="ms-auto">
                    <ol class="breadcrumb mb-0 d-flex align-items-center p-0" style="margin-bottom: 0;">
                        @foreach($items as $item)
                            @if(!$loop->last)
                                <li class="d-flex align-items-center p-0 m-0" style="list-style: none;">
                                    <a href="{{ $item['url'] ?? '#' }}"
                                       class="text-muted text-decoration-none d-flex align-items-center">
                                        @if(!empty($item['icon']))
                                            <span class="d-inline-flex me-1">{!! $item['icon'] !!}</span>
                                        @endif
                                        <span>{{ $item['label'] }}</span>
                                    </a>
                                    <span class="mx-2 text-muted">›</span>
                                </li>
                            @else
                                <li class="d-flex align-items-center p-0 m-0" style="list-style: none;">
                                    <span
                                        class="badge fw-medium fs-2 bg-primary-subtle text-primary d-flex align-items-center">
                                        @if(!empty($item['icon']))
                                            <span class="d-inline-flex me-1">{!! $item['icon'] !!}</span>
                                        @endif
                                        <span>{{ $item['label'] }}</span>
                                    </span>
                                </li>
                            @endif
                        @endforeach
                    </ol>
                </nav>
            </div>
        </div>
    </div>
</div>










