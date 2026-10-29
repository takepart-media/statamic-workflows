<template>
    <!-- The icon that pops up next to marked text, Word-style. Fixed to the
         viewport and teleported to <body> so no transformed ancestor clips or
         shifts it; it follows the selection while the page scrolls. -->
    <Teleport to="body">
        <button
            v-if="position"
            type="button"
            class="fw-selection-comment fixed z-[60] inline-flex size-8 cursor-pointer items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 shadow-md hover:bg-blue-50 hover:text-blue-700 dark:border-gray-700! dark:bg-gray-900! dark:text-gray-300! dark:hover:bg-blue-950! dark:hover:text-blue-300!"
            :style="{ top: `${position.top}px`, left: `${position.left}px` }"
            :aria-label="__('workflows::messages.comment_selection')"
            :title="__('workflows::messages.comment_selection')"
            @mousedown.prevent
            @click="submit"
        >
            <Icon name="mail-chat-bubble-text" class="size-4" />
        </button>
    </Teleport>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { Icon } from '@statamic/cms/ui';
import { selectionIn } from './anchors.js';

const props = defineProps({
    // The element whose passages may be commented on (ContentBlocks' root).
    scope: { type: Object, default: null },
    // Elements inside `scope` that can carry a marked comment.
    selector: { type: String, default: '.fw-prose' },
});

const emit = defineEmits(['comment']);

const position = ref(null);
let current = null;
let frame = null;

function update() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
        current = props.scope ? selectionIn(props.scope, props.selector) : null;
        const rect = current?.rect;

        // Right of the selection's end line, clamped into the viewport.
        position.value = rect && rect.height
            ? {
                  top: Math.max(8, rect.bottom - 32),
                  left: Math.min(window.innerWidth - 40, rect.right + 8),
              }
            : null;
    });
}

function submit() {
    if (!current) return;
    const { prose, quote, offset } = current;
    emit('comment', { prose, quote, offset });
    window.getSelection()?.removeAllRanges();
    position.value = null;
    current = null;
}

onMounted(() => {
    document.addEventListener('selectionchange', update);
    window.addEventListener('scroll', update, true);
    window.addEventListener('resize', update);
});

onUnmounted(() => {
    cancelAnimationFrame(frame);
    document.removeEventListener('selectionchange', update);
    window.removeEventListener('scroll', update, true);
    window.removeEventListener('resize', update);
});
</script>
