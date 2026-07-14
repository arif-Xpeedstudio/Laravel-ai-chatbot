/**
 * All of the client-side behaviour. Plain JavaScript, no framework, no build.
 *
 * The important part is stream(): it listens to the Server-Sent Events endpoint
 * and paints the answer as it arrives.
 */
document.addEventListener('DOMContentLoaded', () => {
    stream();
    composer();
    copyButtons();
    confirmations();
    toggles();
    scrollToBottom();
});

/**
 * If the page rendered an empty assistant bubble, the server is telling us
 * "this conversation is waiting for a reply". Open the stream and fill it.
 */
function stream() {
    const pending = document.getElementById('pending');

    if (!pending) {
        return;
    }

    const text = document.getElementById('pending-text');
    const typing = document.getElementById('typing');
    const send = document.getElementById('send');

    // Plain text while streaming; the formatted Markdown comes from the server
    // on the reload below, once the full answer has been saved.
    text.classList.add('streaming');
    if (send) send.disabled = true;

    const source = new EventSource(pending.dataset.streamUrl);

    source.addEventListener('delta', (event) => {
        typing.classList.add('hidden');
        text.textContent += JSON.parse(event.data).text;
        scrollToBottom();
    });

    source.addEventListener('done', () => {
        source.close();
        // Re-render server-side so the answer appears as real Markdown.
        window.location.reload();
    });

    // Sent by our controller when OpenAI itself failed.
    source.addEventListener('failed', (event) => {
        source.close();
        fail(JSON.parse(event.data).message);
    });

    // Fired by the browser when the connection drops. Closing the source stops
    // EventSource from silently retrying forever.
    source.onerror = () => {
        source.close();

        if (text.textContent === '') {
            fail('Connection lost. Reload the page to try again.');
        }
    };

    function fail(message) {
        typing.classList.add('hidden');
        text.classList.add('prose');
        text.innerHTML = '';

        const p = document.createElement('p');
        p.className = 'flash error';
        p.textContent = message;

        const retry = document.createElement('button');
        retry.className = 'btn';
        retry.textContent = 'Retry';
        retry.onclick = () => window.location.reload();

        text.append(p, retry);
        if (send) send.disabled = false;
    }
}

/**
 * Enter sends, Shift+Enter makes a new line, and the box grows with the text.
 */
function composer() {
    const form = document.getElementById('composer');
    const box = document.getElementById('message');

    if (!form || !box) {
        return;
    }

    const grow = () => {
        box.style.height = 'auto';
        box.style.height = `${box.scrollHeight}px`;
    };

    box.addEventListener('input', grow);
    grow();

    box.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();

            if (box.value.trim() !== '') {
                form.requestSubmit();
            }
        }
    });
}

/**
 * Drop a copy button into every code block the Markdown renderer produced.
 */
function copyButtons() {
    document.querySelectorAll('.prose pre').forEach((pre) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'copy';
        button.textContent = 'Copy';

        button.addEventListener('click', async () => {
            await navigator.clipboard.writeText(pre.innerText);
            button.textContent = 'Copied';
            setTimeout(() => (button.textContent = 'Copy'), 1200);
        });

        pre.appendChild(button);
    });
}

/**
 * Any form with data-confirm asks first. Used by Delete chat.
 */
function confirmations() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });
}

/**
 * data-toggle="#id" shows or hides that element. Used by the Rename button.
 */
function toggles() {
    document.querySelectorAll('[data-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.querySelector(button.dataset.toggle);

            target?.classList.toggle('hidden');
            target?.querySelector('input')?.focus();
        });
    });
}

function scrollToBottom() {
    const messages = document.getElementById('messages');

    if (messages) {
        messages.scrollTop = messages.scrollHeight;
    }
}
