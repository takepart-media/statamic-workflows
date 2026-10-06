<div class="overflow-hidden rounded-xl bg-white shadow-ui-md ring ring-gray-200 dark:bg-gray-850 dark:ring-gray-700/80">
    <div class="flex h-full min-h-54 flex-col">
        <header class="flex min-h-[49px] items-center border-b border-gray-200 px-4.5 py-2 dark:border-gray-700">
            <h2 class="font-medium text-gray-800 dark:text-gray-200">{{ __('workflows::messages.widget_assigned_title') }}</h2>
        </header>

        <div class="flex-1 px-4.5 py-3">
            @if ($posts->isEmpty())
                <p class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                    {{ __('workflows::messages.widget_no_assigned') }}
                </p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($posts as $post)
                        <li class="flex items-center gap-3 py-2">
                            <a href="{{ $post['url'] }}" class="min-w-0 flex-1 truncate text-sm text-blue-600 hover:underline dark:text-blue-400">
                                {{ $post['title'] }}
                            </a>
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-2xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-200">
                                {{ $post['state_label'] }}
                            </span>
                        </li>
                    @endforeach
                </ul>
                @if ($remaining > 0)
                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">+{{ $remaining }}</p>
                @endif
            @endif
        </div>

        <footer class="border-t border-gray-200 px-4.5 py-2 dark:border-gray-700">
            <a href="{{ $managerUrl }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">
                {{ __('workflows::messages.widget_open_manager') }} &rarr;
            </a>
        </footer>
    </div>
</div>
