<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Recent Operational Activity
        </x-slot>

        @if ($activity->isEmpty())
            <x-filament::empty-state
                icon="heroicon-o-clock"
                heading="No activity yet"
                description="Competition, draw and winner events will appear here as they happen."
            />
        @else
            <div class="fi-ta-ctn overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Activity</th>
                            <th class="px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Target</th>
                            <th class="px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Time</th>
                            <th class="px-3 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activity as $entry)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-3 py-2">
                                    <x-filament::badge color="gray">
                                        {{ $entry['event'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $entry['target'] }}
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $entry['timestamp']?->diffForHumans() }}
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $entry['reason'] ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
