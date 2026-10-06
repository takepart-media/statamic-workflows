<template>
    <!-- The "add" menu (n8n's node creator): opened from the "+" beside a node's
         output, a line dropped on empty canvas, Tab, or a context menu. The
         Popover hangs off an invisible anchor placed at the requested point
         (`x`/`y`, px relative to the editor root). -->
    <Popover
        :open="open"
        side="bottom"
        align="start"
        inset
        :offset="6"
        class="w-80!"
        @update:open="(v) => $emit('update:open', v)"
    >
        <template #trigger>
            <span class="wf-add-anchor" :style="{ left: `${x}px`, top: `${y}px` }" aria-hidden="true" />
        </template>
        <div ref="root" class="wf-add-menu" :aria-label="title" @keydown="onKeydown">
            <div class="border-b border-gray-200 p-2 dark:border-gray-700!">
                <p class="truncate px-1 pb-1.5 text-2xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ title }}</p>
                <Input
                    v-model="query"
                    size="sm"
                    icon="magnifying-glass"
                    :placeholder="__('workflows::messages.editor_add_search')"
                    :aria-label="__('workflows::messages.editor_add_search')"
                />
            </div>
            <div class="max-h-80 overflow-y-auto p-1" role="listbox" :aria-label="title">
                <p v-if="!visible.length" class="px-2 py-2 text-sm text-gray-500 dark:text-gray-400">{{ __('workflows::messages.editor_none') }}</p>
                <template v-for="group in groups" :key="group.key">
                    <p v-if="group.label" class="px-2 pb-1 pt-2 text-2xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ group.label }}
                    </p>
                    <button
                        v-for="item in group.items"
                        :key="item.key"
                        type="button"
                        role="option"
                        class="wf-add-item"
                        :class="{ 'wf-add-item--active': item.key === activeKey }"
                        :aria-selected="item.key === activeKey"
                        :aria-disabled="item.disabled || undefined"
                        :title="item.title || undefined"
                        :data-key="item.key"
                        @mousemove="activeKey = item.key"
                        @click="pick(item)"
                    >
                        <Icon :name="item.icon" class="size-3.5 shrink-0 text-gray-500" />
                        <span class="min-w-0 flex-1 truncate">{{ item.text }}</span>
                        <code v-if="item.hint" class="shrink-0 font-mono text-2xs text-gray-500">{{ item.hint }}</code>
                    </button>
                </template>
            </div>
        </div>
    </Popover>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Icon, Input, Popover } from '@statamic/cms/ui';

const props = defineProps({
    open: { type: Boolean, default: false },
    // Anchor, px relative to the editor root.
    x: { type: Number, default: 0 },
    y: { type: Number, default: 0 },
    title: { type: String, default: '' },
    // [{ key, icon, text, hint?, group, search?, disabled?, title? }]
    items: { type: Array, default: () => [] },
    // { [group]: label } — a group without a label is shown without a heading.
    groupLabels: { type: Object, default: () => ({}) },
});

// pick: the chosen item (disabled ones too — the parent says why they can't run).
const emit = defineEmits(['update:open', 'pick']);

const root = ref(null);
const query = ref('');
const activeKey = ref(null);

const visible = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.items;
    return props.items.filter((item) => `${item.text} ${item.hint ?? ''} ${item.search ?? ''}`.toLowerCase().includes(q));
});

const groups = computed(() => {
    const out = [];
    for (const item of visible.value) {
        let group = out.find((g) => g.key === item.group);
        if (!group) {
            group = { key: item.group, label: props.groupLabels[item.group] ?? '', items: [] };
            out.push(group);
        }
        group.items.push(item);
    }
    return out;
});

// The flat order the arrow keys walk.
const flat = computed(() => groups.value.flatMap((g) => g.items));

watch(flat, (items) => {
    if (!items.some((i) => i.key === activeKey.value)) activeKey.value = items.find((i) => !i.disabled)?.key ?? null;
}, { immediate: true });

watch(() => props.open, (open) => {
    if (!open) return;
    query.value = '';
    activeKey.value = flat.value.find((i) => !i.disabled)?.key ?? null;
    nextTick(() => requestAnimationFrame(() => root.value?.querySelector('input')?.focus()));
});

function move(step) {
    const items = flat.value;
    if (!items.length) return;
    let i = items.findIndex((item) => item.key === activeKey.value);
    i = (i + step + items.length) % items.length;
    activeKey.value = items[i].key;
    root.value?.querySelector(`[data-key="${CSS.escape(items[i].key)}"]`)?.scrollIntoView({ block: 'nearest' });
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Enter') {
        const item = flat.value.find((i) => i.key === activeKey.value);
        if (item) {
            event.preventDefault();
            pick(item);
        }
    }
}

function pick(item) {
    emit('pick', item);
}
</script>
