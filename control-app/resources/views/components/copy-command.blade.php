@props(['text', 'terminal' => true, 'link' => false])

<div class="space-y-1" x-data="{
    state: null,
    copy() {
        const text = this.$refs.text.innerText.trim();
        const range = document.createRange();
        range.selectNodeContents(this.$refs.text);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        const fallback = () => {
            let copied = false;
            try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
            this.state = copied ? 'copied' : 'blocked';
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => { this.state = 'copied'; }, fallback);
        } else {
            fallback();
        }
    },
}">
    <div class="flex flex-wrap items-center gap-2">
        @if($link)
            <a x-ref="text" href="{{ $text }}" target="_blank" class="flex-1 min-w-0 text-sm text-blue-500 break-all">{{ $text }}</a>
        @else
            <pre x-ref="text" class="flex-1 min-w-0 overflow-x-auto px-3 py-2 rounded bg-gray-900 text-gray-100 text-xs font-mono">{{ $text }}</pre>
        @endif
        <flux:button size="sm" variant="filled" color="blue" icon="clipboard-document" x-on:click="copy()">
            <span x-text="state === 'copied' ? 'Copied' : 'Copy'">Copy</span>
        </flux:button>
    </div>
    <p x-show="state === 'copied'" x-cloak class="text-xs text-green-600 dark:text-green-400">
        @if($terminal)
            Copied. Paste it into your terminal with <span class="font-mono">Ctrl+Shift+V</span> (plain Ctrl+V doesn't paste in most Linux terminals).
        @else
            Copied.
        @endif
    </p>
    <p x-show="state === 'blocked'" x-cloak class="text-xs text-yellow-700 dark:text-yellow-400">
        @if($terminal)
            Your browser blocked copying. The command is selected: press <span class="font-mono">Ctrl+C</span>, or paste it straight into the terminal with the middle mouse button.
        @else
            Your browser blocked copying. The text is selected: press <span class="font-mono">Ctrl+C</span> to copy it.
        @endif
    </p>
</div>
