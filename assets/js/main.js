/* LitePic V2.2 JavaScript bundle */

/* ===== script.js ===== */
window.ImgEt = window.ImgEt || {};

window.LitePicLoader = window.LitePicLoader || {
    show() {
        document.documentElement.classList.add('is-global-loading');
    },
    hide() {
        document.documentElement.classList.remove('is-global-loading');
    },
};

window.addEventListener('beforeunload', () => {
    if (window.LitePicLoader) window.LitePicLoader.show();
});

window.addEventListener('pageshow', () => {
    if (window.LitePicLoader) window.LitePicLoader.hide();
});

/* =============================================================
 * Pjax — drop-in HTML-fragment navigation for tabbed pages
 *
 * Why: clicking a settings tab used to hit `/settings?tab=storage`
 * with a full document load. The server returns the same header /
 * nav / footer every time, so the visible side-effect is the top
 * "设置" button briefly losing its active state and the page
 * scrollbar/state being thrown away.
 *
 * What this does:
 *   • Intercept clicks on `[data-pjax]` links
 *   • fetch() the target URL, parse the returned HTML
 *   • Find the new `[data-pjax-container]` (we mark <main> with it)
 *   • Replace the current container with the new one
 *   • Manually re-execute any `<script>` tags inside the new
 *     container — innerHTML-injected scripts don't run on their own
 *   • history.pushState so the URL stays correct, popstate handled
 *     for back/forward
 *   • Top header / footer / <head> stays untouched → no flicker
 *
 * Falls back to a normal full-page navigation if anything goes wrong
 * (server returned no container, fetch failed, etc.) so the page
 * still works with JS off / on weird CDN errors.
 *
 * The inline scripts inside a pjax container should be written
 * idempotently — they run once on first load, then again on every
 * swap. The settings page already wraps init in a function that
 * detects whether the container has been bound.
 * ============================================================= */
window.Pjax = {
    containerSelector: '[data-pjax-container]',
    linkSelector: 'a[data-pjax]',
    loadingClass: 'is-pjax-loading',
    isNavigating: false,

    init() {
        if (this._inited) return;
        this._inited = true;

        // Click delegation — intercept every click on a pjax link
        document.addEventListener('click', (e) => {
            const link = e.target.closest(this.linkSelector);
            if (!link) return;
            // Respect modifiers (cmd-click for new tab, etc.)
            if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            if (link.target && link.target !== '' && link.target !== '_self') return;
            // Same-origin only — external links go through normal nav
            try {
                const url = new URL(link.href, window.location.origin);
                if (url.origin !== window.location.origin) return;
            } catch { return; }

            e.preventDefault();
            // data-pjax="soft" — fetch + swap content but DON'T touch the URL.
            // Used by tab navigation where we want subtab content swapping
            // without polluting the address bar / browser history with
            // ?tab=XYZ permutations. Refresh in this mode goes back to
            // the page's default view.
            const soft = link.getAttribute('data-pjax') === 'soft';
            const preserveScroll = link.getAttribute('data-pjax-scroll') === 'preserve'
                || !!link.closest('[data-pjax-scroll="preserve"]');
            this.go(link.href, { push: !soft, preserveScroll });
        });

        // Browser back / forward
        window.addEventListener('popstate', (e) => {
            // Only swap if we're navigating to something we owned
            if (e.state && e.state.pjax) {
                this.go(window.location.href, { push: false });
            }
        });

        // Mark the initial state as pjax so popstate works on first back
        if (!history.state || !history.state.pjax) {
            history.replaceState({ pjax: true }, '', window.location.href);
        }
    },

    async go(url, opts = {}) {
        const { push = true, preserveScroll = false } = opts;
        if (this.isNavigating) return; // ignore rapid double-clicks
        const container = document.querySelector(this.containerSelector);
        if (!container) {
            window.location.href = url;
            return;
        }
        const scrollPosition = preserveScroll
            ? { x: window.scrollX || 0, y: window.scrollY || 0 }
            : null;

        this.isNavigating = true;
        container.classList.add(this.loadingClass);
        if (window.LitePicLoader) window.LitePicLoader.show();
        document.dispatchEvent(new CustomEvent('pjax:loading', {
            detail: { url, container },
        }));

        try {
            const resp = await fetch(url, {
                headers: {
                    'X-Pjax': '1',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'follow',
            });

            if (!resp.ok) {
                throw new Error('HTTP ' + resp.status);
            }

            const html = await resp.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newContainer = doc.querySelector(this.containerSelector);

            if (!newContainer) {
                // Server returned a page without our container — full reload
                window.location.href = url;
                return;
            }

            // Update title to match the new page
            if (doc.title) document.title = doc.title;

            // Swap. We replace the OLD container with the NEW one rather
            // than just innerHTML to preserve attributes (data-*, class).
            container.replaceWith(newContainer);
            newContainer.classList.remove(this.loadingClass);

            // <script> tags created by replaceWith don't auto-execute;
            // walk through and recreate them so they run.
            this.executeScripts(newContainer);

            // History
            if (push) {
                history.pushState({ pjax: true }, '', url);
            }

            if (scrollPosition) {
                window.scrollTo(scrollPosition.x, scrollPosition.y);
            } else {
                // Reset scroll to top of the new tab
                window.scrollTo(0, 0);
            }

            // Notify any subscribers (theme system, nav indicator, etc.)
            document.dispatchEvent(new CustomEvent('pjax:loaded', {
                detail: { url, container: newContainer },
            }));
        } catch (err) {
            console.error('[Pjax] navigation failed, falling back to full reload:', err);
            window.location.href = url;
        } finally {
            this.isNavigating = false;
            if (window.LitePicLoader) window.LitePicLoader.hide();
            // (loading class removed during swap; if swap failed and we
            //  fell back to window.location.href, the page is reloading
            //  anyway so cleanup doesn't matter)
        }
    },

    /**
     * <script> elements injected via innerHTML / replaceWith / DOMParser
     * don't execute by spec. We walk the new container and recreate each
     * one, which DOES trigger execution.
     */
    executeScripts(container) {
        const scripts = Array.from(container.querySelectorAll('script'));
        scripts.forEach((oldScript) => {
            const fresh = document.createElement('script');
            for (const attr of oldScript.attributes) {
                fresh.setAttribute(attr.name, attr.value);
            }
            if (oldScript.src) {
                // External script: just swap (browser fetches + executes)
                fresh.async = false;
            } else {
                fresh.textContent = oldScript.textContent;
            }
            oldScript.replaceWith(fresh);
        });
    },
};

document.addEventListener('DOMContentLoaded', () => {
    if (window.Pjax) window.Pjax.init();
});

/**
 * HTML 转义辅助函数（防止 XSS）
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    if (typeof str !== 'string') return String(str ?? '');
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * 工具类 - 核心功能实现
 */
window.ImgEt.Utils = {
    /**
     * 复制到剪贴板
     * @param {string} text 要复制的文本
     * @param {HTMLElement} [btn] 可选的按钮元素，用于显示动画效果
     * @returns {Promise<void>}
     */
    async copyToClipboard(text, btn = null) {
        if (!text) {
            this.showNotification('没有可复制的内容', 'error');
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
            
            if (btn) {
                // 添加复制成功动画
                const originalHtml = btn.innerHTML;
                btn.classList.add('success');
                btn.innerHTML = '<i class="fa-light fa-check"></i>';
                
                setTimeout(() => {
                    btn.classList.remove('success');
                    btn.innerHTML = originalHtml;
                }, 1000);
            }
            
            this.showNotification('复制成功', 'success');
        } catch (error) {
            console.error('Copy failed:', error);
            this.showNotification('复制失败，请手动复制', 'error');
        }
    },

    /**
     * 显示通知消息
     * @param {string} message 消息内容
     * @param {string} type 消息类型 (success/error/warning/info)
     * @param {Object} options 显示选项
     */
    showNotification(message, type = 'info', options = {}) {
        try {
            const normalizedOptions = options && typeof options === 'object' ? options : {};
            const variants = normalizedOptions.variant
                ? (Array.isArray(normalizedOptions.variant)
                    ? normalizedOptions.variant
                    : String(normalizedOptions.variant).split(/\s+/))
                : [];
            const notification = variants.includes('auth')
                ? this.getAuthNotificationContainer()
                : variants.includes('process')
                    ? this.getProcessNotificationContainer()
                    : document.getElementById('notification');
            if (!notification) return;

            const item = this.createNotificationItem(message, type, normalizedOptions);
            notification.appendChild(item);
            
            // 添加显示动画
            requestAnimationFrame(() => item.classList.add('show'));
            
            // 自动关闭
            const duration = Number.isFinite(normalizedOptions.duration) ? normalizedOptions.duration : 3000;
            let hideTimeout = null;
            if (duration > 0) {
                hideTimeout = setTimeout(() => this.hide(item), duration);
            }
            
            // 手动关闭
            const closeBtn = item.querySelector('.notification-close');
            if (closeBtn) {
                closeBtn.onclick = () => {
                    if (hideTimeout) clearTimeout(hideTimeout);
                    this.hide(item);
                };
            }
            return item;
        } catch (error) {
            console.error('Failed to show notification:', error);
        }
    },

    /**
     * 获取登录提示专用容器
     * @private
     */
    getAuthNotificationContainer() {
        let notification = document.getElementById('authNotification');
        if (!notification) {
            notification = document.createElement('div');
            notification.id = 'authNotification';
            notification.className = 'auth-toast-container';
            document.body.appendChild(notification);
        }
        return notification;
    },

    /**
     * 获取图片处理结果通知容器
     * @private
     */
    getProcessNotificationContainer() {
        let notification = document.getElementById('notification');
        if (!notification) {
            notification = document.createElement('div');
            notification.id = 'notification';
            notification.className = 'notification-container';
            document.body.appendChild(notification);
        }
        return notification;
    },

    /**
     * 创建通知项
     * @private
     */
    createNotificationItem(message, type, options = {}) {
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        const item = document.createElement('div');
        const classes = ['notification-item', type];
        let isAuthNotification = false;
        if (options.variant) {
            const variants = Array.isArray(options.variant)
                ? options.variant
                : String(options.variant).split(/\s+/);
            variants.filter(Boolean).forEach(variant => classes.push(`notification-${variant}`));
            isAuthNotification = variants.includes('auth');
        }
        item.className = classes.join(' ');
        item.setAttribute('role', 'alert');
        const iconHtml = isAuthNotification
            ? `<span class="notification-auth-icon" aria-hidden="true"><i class="fa-light ${icons[type] || icons.info}"></i></span>`
            : `<i class="fa-light ${icons[type] || icons.info}" aria-hidden="true"></i>`;

        if (options.title || options.detail || options.meta) {
            const title = options.title || message;
            const detail = options.detail || '';
            const meta = options.meta || '';
            item.innerHTML = `
                ${isAuthNotification
                    ? `<span class="notification-auth-icon" aria-hidden="true"><i class="fa-light ${options.icon || icons[type] || icons.info}"></i></span>`
                    : `<i class="fa-light ${options.icon || icons[type] || icons.info}" aria-hidden="true"></i>`}
                <div class="notification-copy">
                    <strong>${escapeHtml(title)}</strong>
                    ${detail ? `<span>${escapeHtml(detail)}</span>` : ''}
                    ${meta ? `<em>${escapeHtml(meta)}</em>` : ''}
                </div>
                <button class="notification-close" aria-label="关闭通知">
                    <i class="fa-light fa-times"></i>
                </button>
            `;
        } else {
            item.innerHTML = `
                ${iconHtml}
                <span class="flex-1 text-sm leading-relaxed">${escapeHtml(message)}</span>
                <button class="notification-close" aria-label="关闭通知">
                    <i class="fa-light fa-times"></i>
                </button>
            `;
        }

        return item;
    },

    /**
     * 隐藏通知
     * @private
     */
    hide(item) {
        if (!item) return;
        item.classList.remove('show');
        setTimeout(() => item.remove(), 300);
    },

    /**
     * 防抖函数
     * @param {Function} func 要执行的函数
     * @param {number} wait 等待时间(毫秒)
     * @returns {Function} 防抖后的函数
     */
    debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * 节流函数
     * @param {Function} func 要执行的函数
     * @param {number} limit 限制时间(毫秒)
     * @returns {Function} 节流后的函数
     */
    throttle(func, limit = 300) {
        let inThrottle;
        return function executedFunction(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    /**
     * 显示复制对话框
     * @param {string} filename 文件名
     * @param {string} url 图片URL
     */
    showCopyDialog(filename, url) {
        const safeFilename = escapeHtml(filename);
        const safeUrl = escapeHtml(url);
        const content = `
            <div class="copy-options">
                <div class="copy-option">
                    <div class="copy-option-header">URL</div>
                    <div class="copy-option-content">
                        <input type="text" value="${safeUrl}" readonly class="flex-1 bg-light border border-border px-3 py-2 text-sm text-dark">
                        <button class="copy-btn inline-flex items-center justify-center w-10 h-10 bg-light border-l border-border text-gray hover:text-primary hover:bg-primary/5" data-copy="${safeUrl}">
                            <i class="fa-light fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="copy-option">
                    <div class="copy-option-header">HTML</div>
                    <div class="copy-option-content">
                        <input type="text" value="&lt;img src=&quot;${safeUrl}&quot; alt=&quot;${safeFilename}&quot;&gt;" readonly class="flex-1 bg-light border border-border px-3 py-2 text-sm text-dark">
                        <button class="copy-btn inline-flex items-center justify-center w-10 h-10 bg-light border-l border-border text-gray hover:text-primary hover:bg-primary/5" data-copy="&lt;img src=&quot;${safeUrl}&quot; alt=&quot;${safeFilename}&quot;&gt;">
                            <i class="fa-light fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="copy-option">
                    <div class="copy-option-header">Markdown</div>
                    <div class="copy-option-content">
                        <input type="text" value="![${safeFilename}](${safeUrl})" readonly class="flex-1 bg-light border border-border px-3 py-2 text-sm text-dark">
                        <button class="copy-btn inline-flex items-center justify-center w-10 h-10 bg-light border-l border-border text-gray hover:text-primary hover:bg-primary/5" data-copy="![${safeFilename}](${safeUrl})">
                            <i class="fa-light fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="copy-option">
                    <div class="copy-option-header">BBCode</div>
                    <div class="copy-option-content">
                        <input type="text" value="[img]${safeUrl}[/img]" readonly class="flex-1 bg-light border border-border px-3 py-2 text-sm text-dark">
                        <button class="copy-btn inline-flex items-center justify-center w-10 h-10 bg-light border-l border-border text-gray hover:text-primary hover:bg-primary/5" data-copy="[img]${safeUrl}[/img]">
                            <i class="fa-light fa-copy"></i>
                        </button>
                    </div>
                </div>
            </div>`;

        ImgEt.DialogManager.showCustomDialog('复制图片链接', content);

        // 为复制按钮添加事件处理
        const dialog = document.querySelector('.custom-dialog');
        dialog?.addEventListener('click', async e => {
            const btn = e.target.closest('.copy-btn');
            if (!btn) return;

            const textToCopy = btn.dataset.copy;
            if (textToCopy) {
                await this.copyToClipboard(textToCopy, btn);
            }
        });
    },
};

/**
 * 批量进度卡(单例) - 显示批量操作的实时进度
 *
 * 与右上角 toast 分工:
 *   - 右上角 toast (ImgEt.Utils.showNotification) → 状态广播
 *     "批量转换中..." / "批量转换完成: 9 张成功"
 *   - 底部进度卡 (本组件)                       → 实时进度
 *     标题 + N/M 张 + 进度条 + 百分比
 *
 * 使用方式:
 *   ImgEt.BatchProgress.show('convert', '批量转换', 0, 10);   // 开始
 *   ImgEt.BatchProgress.show('convert', '批量转换', 5, 10);   // 进度更新
 *   ImgEt.BatchProgress.done();                                // 闪一下 100% 然后消失
 *   // 或者 ImgEt.BatchProgress.hide(); 直接消失
 *
 * variant: 'compress' | 'convert' | 'delete' — 决定强调色
 */
if (!window.ImgEt.BatchProgress) {
    window.ImgEt.BatchProgress = {
        _el: null,
        _hideTimer: null,
        _currentVariant: null,

        /**
         * 创建/复用 DOM。第一次调用时插入到 body,后续 show 只更新内容。
         * 不在模块加载时预创建,因为很多页面根本不用批量操作。
         */
        _ensure() {
            if (this._el && document.body.contains(this._el)) return this._el;
            const el = document.createElement('div');
            el.className = 'batch-progress';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            el.innerHTML = `
                <div class="batch-progress__head">
                    <i class="batch-progress__icon fa-light fa-spinner-third fa-spin" aria-hidden="true"></i>
                    <div class="batch-progress__main">
                        <span class="batch-progress__title"></span>
                        <span class="batch-progress__count"></span>
                    </div>
                    <span class="batch-progress__percent">0%</span>
                    <button type="button" class="batch-progress__close" aria-label="关闭" title="关闭">
                        <i class="fa-light fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="batch-progress__bar">
                    <div class="batch-progress__bar-fill"></div>
                </div>
            `;
            document.body.appendChild(el);
            // 关闭按钮 — 手动收起进度卡(不影响后台已入队的任务)
            el.querySelector('.batch-progress__close')?.addEventListener('click', () => this.hide());
            this._el = el;
            return el;
        },

        /**
         * 显示或更新进度卡。done/total 都是 number,total=0 时百分比按 0 算。
         */
        show(variant, label, done, total) {
            const el = this._ensure();
            if (this._hideTimer) {
                clearTimeout(this._hideTimer);
                this._hideTimer = null;
            }
            // 切 variant 时清理旧的 modifier class — 不然连续做不同操作会叠
            const variants = ['compress', 'convert', 'delete'];
            for (const v of variants) {
                el.classList.toggle('batch-progress--' + v, v === variant);
            }
            this._currentVariant = variant;
            el.classList.remove('is-complete');

            const safeDone = Math.max(0, Math.min(done | 0, total | 0));
            const safeTotal = Math.max(0, total | 0);
            const percent = safeTotal > 0 ? Math.round((safeDone / safeTotal) * 100) : 0;

            const titleEl   = el.querySelector('.batch-progress__title');
            const countEl   = el.querySelector('.batch-progress__count');
            const percentEl = el.querySelector('.batch-progress__percent');
            const fillEl    = el.querySelector('.batch-progress__bar-fill');
            const iconEl    = el.querySelector('.batch-progress__icon');

            if (titleEl)   titleEl.textContent = label || '';
            if (countEl)   countEl.textContent = safeTotal > 0 ? `${safeDone}/${safeTotal} 张` : '';
            if (percentEl) percentEl.textContent = percent + '%';
            if (fillEl)    fillEl.style.width = percent + '%';
            // 处理中:转 spinner;100% 时切 check (但 hide/done 接管最终视觉)
            if (iconEl) {
                iconEl.className = 'batch-progress__icon fa-light fa-spinner-third fa-spin';
            }

            // 触发滑入(放进 rAF 避免插入瞬间的 transition 失效)
            requestAnimationFrame(() => el.classList.add('is-visible'));
        },

        /**
         * 操作完成 — 把进度条拉满、换图标,稍微停留再淡出。
         * 提供视觉反馈"真的跑完了",而不是直接消失感觉像中断。
         */
        done() {
            if (!this._el) return;
            const el = this._el;
            el.classList.add('is-complete');
            const fillEl = el.querySelector('.batch-progress__bar-fill');
            const percentEl = el.querySelector('.batch-progress__percent');
            const iconEl = el.querySelector('.batch-progress__icon');
            if (fillEl)    fillEl.style.width = '100%';
            if (percentEl) percentEl.textContent = '100%';
            if (iconEl)    iconEl.className = 'batch-progress__icon fa-light fa-circle-check';
            // 800ms 让用户看到完成态,再滑下去
            this._hideTimer = setTimeout(() => this.hide(), 800);
        },

        /**
         * 立即隐藏。用于失败 / 中止的场景(完成走 done()).
         */
        hide() {
            if (this._hideTimer) {
                clearTimeout(this._hideTimer);
                this._hideTimer = null;
            }
            if (!this._el) return;
            this._el.classList.remove('is-visible');
            // CSS 过渡完再清 DOM 不必要 — 复用同一个 el 性能更好
        },
    };
}

/**
 * 对话框管理器类 - 处理对话框相关功能
 */
if (!window.ImgEt.DialogManager) {
    window.ImgEt.DialogManager = {
        activeDialogs: [],

        closeAllDialogs() {
            this.activeDialogs.forEach(dialog => {
                const closeHandler = dialog.closeHandler;
                if (typeof closeHandler === 'function') {
                    closeHandler();
                }
            });
            this.activeDialogs = [];
        },

        showCustomDialog(title, content) {
            const safeTitle = escapeHtml(title);
            const dialog = document.createElement('div');
            dialog.className = 'custom-dialog';

            dialog.innerHTML = `
                <div class="custom-dialog-content">
                    <div class="dialog-header">
                        <h3 class="flex items-center gap-2 text-dark">${safeTitle}</h3>
                        <button type="button" class="dialog-close">
                            <i class="fa-light fa-times"></i>
                        </button>
                    </div>
                    <div class="dialog-body">
                        ${content}
                    </div>
                </div>
            `;

            document.body.appendChild(dialog);
            this.activeDialogs.push(dialog);

            // 显示动画
            requestAnimationFrame(() => dialog.classList.add('active'));

            // 关闭处理
            const close = () => {
                dialog.classList.remove('active');
                setTimeout(() => {
                    dialog.remove();
                    this.activeDialogs = this.activeDialogs.filter(d => d !== dialog);
                    document.removeEventListener('keydown', escHandler);
                }, 300);
            };

            const closeBtn = dialog.querySelector('.dialog-close');
            closeBtn.addEventListener('click', close);

            const clickOutsideHandler = e => {
                if (e.target === dialog) close();
            };
            dialog.addEventListener('click', clickOutsideHandler);

            dialog.closeHandler = close;

            // ESC 键关闭
            const escHandler = e => {
                if (e.key === 'Escape') {
                    close();
                }
            };
            document.addEventListener('keydown', escHandler);
        },

        showConfirmDialog(title, message, onConfirm, options = {}) {
            // 关闭所有已存在的确认弹窗，防止叠加
            document.querySelectorAll('.confirm-dialog').forEach(d => {
                if (typeof d.closeHandler === 'function') d.closeHandler();
            });

            const normalizedOptions = options && typeof options === 'object' ? options : {};
            const isDanger = normalizedOptions.danger === true;
            const safeTitle = escapeHtml(title);
            const safeMessage = escapeHtml(message);
            const cancelText = escapeHtml(normalizedOptions.cancelText || '取消');
            const confirmText = escapeHtml(normalizedOptions.confirmText || (isDanger ? '删除' : '确认'));
            const iconClass = isDanger ? 'fa-trash' : 'fa-check';
            // 可选 toggle(如「保留原图」)。其状态在确认时回传给 onConfirm(checked)。
            const toggleOpt = normalizedOptions.toggle && typeof normalizedOptions.toggle === 'object' ? normalizedOptions.toggle : null;
            const toggleHtml = toggleOpt ? `
                        <label class="confirm-dialog-toggle">
                            <input type="checkbox" class="confirm-dialog-toggle-input" ${toggleOpt.checked === false ? '' : 'checked'}>
                            <span class="confirm-dialog-toggle-track" aria-hidden="true"></span>
                            <span class="confirm-dialog-toggle-label">${escapeHtml(toggleOpt.label || '')}</span>
                        </label>` : '';
            const dialog = document.createElement('div');
            dialog.className = `confirm-dialog${isDanger ? ' confirm-dialog-danger' : ''}`;
            dialog.innerHTML = `
                <div class="confirm-dialog-content">
                    <div class="confirm-dialog-body">
                        <h3 class="confirm-dialog-title">${safeTitle}</h3>
                        <p class="confirm-dialog-message">${safeMessage}</p>
                        ${toggleHtml}
                        <div class="confirm-dialog-actions">
                            <button type="button" class="confirm-dialog-btn confirm-dialog-cancel">
                                ${cancelText}
                            </button>
                            <button type="button" class="confirm-dialog-btn confirm-dialog-submit">
                                <i class="fa-light ${iconClass}" aria-hidden="true"></i>
                                <span>${confirmText}</span>
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(dialog);
            this.activeDialogs.push(dialog);

            // 改进关闭处理：防止重复关闭，清理所有监听器
            const closeDialog = (confirmed = false) => {
                if (dialog.isClosing) return;
                dialog.isClosing = true;
                if (!confirmed && typeof normalizedOptions.onCancel === 'function') {
                    normalizedOptions.onCancel();
                }
                dialog.classList.remove('active');
                setTimeout(() => {
                    dialog.removeEventListener('click', clickOutsideHandler);
                    document.removeEventListener('keydown', escHandler);
                    dialog.remove();
                    this.activeDialogs = this.activeDialogs.filter(d => d !== dialog);
                }, 300);
            };

            dialog.closeHandler = closeDialog; // 挂载关闭句柄

            dialog.querySelector('.confirm-dialog-cancel').addEventListener('click', () => closeDialog(false));
            dialog.querySelector('.confirm-dialog-submit').addEventListener('click', () => {
                if (typeof onConfirm === 'function' && !dialog.confirmed) {
                    dialog.confirmed = true;
                    const toggleInput = dialog.querySelector('.confirm-dialog-toggle-input');
                    onConfirm(toggleInput ? toggleInput.checked : undefined);
                }
                closeDialog(true);
            });

            const clickOutsideHandler = e => {
                if (e.target === dialog) closeDialog(false);
            };
            dialog.addEventListener('click', clickOutsideHandler);

            const escHandler = e => {
                if (e.key === 'Escape') {
                    closeDialog(false);
                }
            };
            document.addEventListener('keydown', escHandler);

            requestAnimationFrame(() => dialog.classList.add('active'));
        },

        confirm(title, message, options = {}) {
            return new Promise(resolve => {
                this.showConfirmDialog(title, message, () => resolve(true), {
                    ...options,
                    onCancel: () => resolve(false),
                });
            });
        },

        alert(title, message, options = {}) {
            return new Promise(resolve => {
                this.showConfirmDialog(title, message, () => resolve(true), {
                    ...options,
                    confirmText: options.confirmText || '知道了',
                    cancelText: options.cancelText || '',
                    onCancel: () => resolve(false),
                });
                const dialogs = Array.from(document.querySelectorAll('.confirm-dialog'));
                const dialog = dialogs[dialogs.length - 1];
                dialog?.querySelector('.confirm-dialog-cancel')?.remove();
            });
        },
    };
}

// ESC 键关闭所有对话框
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ImgEt.DialogManager.closeAllDialogs();
    }
});

// 初始化代码
document.addEventListener('DOMContentLoaded', () => {
    try {
        // 检查必要组件
        if (!window.ImgEt || !window.ImgEt.Utils || !window.ImgEt.DialogManager) {
            throw new Error('Core components not loaded');
        }

        // 添加全局错误处理（使用 addEventListener 避免覆盖其他处理器）
        window.addEventListener('error', (event) => {
            console.error('Global error:', event.error);
            ImgEt.Utils.showNotification('系统错误，请刷新页面重试', 'error');
        });
    } catch (error) {
        console.error('Script initialization failed:', error);
    }
});

function initLicenseDialog() {
    const trigger = document.querySelector('[data-license-dialog]');
    if (!trigger || trigger.dataset.licenseBound === '1') return;

    trigger.dataset.licenseBound = '1';
    trigger.addEventListener('click', () => {
        if (!window.ImgEt?.DialogManager) return;
        const version = String(window.LITEPIC_VERSION || '3.4.7');

        const content = `
            <div class="litepic-license-dialog">
                <!-- Hero: logo + 名称 + 版本徽章 + 副标题 -->
                <header class="license-hero">
                    <span class="license-hero-logo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="32" height="32" xmlns="http://www.w3.org/2000/svg">
                            <path fill="#0052D9" d="M12 0c4.5 0 6.75 0 8.25.75 1.75.75 2.25 1.25 3 3C24 5.25 24 7.5 24 12s0 6.75-.75 8.25c-.75 1.75-1.25 2.25-3 3C18.75 24 16.5 24 12 24s-6.75 0-8.25-.75c-1.75-.75-2.25-1.25-3-3C0 18.75 0 16.5 0 12s0-6.75.75-8.25c.75-1.75 1.25-2.25 3-3C5.25 0 7.5 0 12 0z"/>
                            <!-- L 和右上角点同步放大 ~1.4x（围绕 viewBox 中心 12,12 缩放），
                                 让品牌字模在 squircle 内更醒目，不再像被吞噬的小标。 -->
                            <path d="M4.2 4.3h3.2v12.2h6.2v2.9H4.2V4.3z" fill="#fff"/>
                            <circle cx="18" cy="7" r="2.4" fill="#fff"/>
                        </svg>
                    </span>
                    <div class="license-hero-text">
                        <h3 class="license-hero-title">
                            LitePic
                            <span class="license-hero-version">v${version}</span>
                        </h3>
                        <p class="license-hero-tagline">轻量级自托管 PHP 图床，零依赖、单目录部署</p>
                    </div>
                </header>

                <!-- 信息卡片 — 仓库 / 作者 / 协议 / Issues -->
                <div class="license-cards">
                    <a class="license-card" href="https://github.com/gentpan/LitePic" target="_blank" rel="noopener noreferrer">
                        <i class="fa-light fa-code-branch" aria-hidden="true"></i>
                        <div class="license-card-body">
                            <span class="license-card-label">仓库</span>
                            <span class="license-card-value">gentpan/LitePic</span>
                        </div>
                    </a>
                    <a class="license-card" href="https://xifeng.net" target="_blank" rel="noopener noreferrer">
                        <i class="fa-light fa-user-pen" aria-hidden="true"></i>
                        <div class="license-card-body">
                            <span class="license-card-label">作者</span>
                            <span class="license-card-value">@gentpan</span>
                        </div>
                    </a>
                    <div class="license-card">
                        <i class="fa-light fa-scale-balanced" aria-hidden="true"></i>
                        <div class="license-card-body">
                            <span class="license-card-label">协议</span>
                            <span class="license-card-value">MIT License</span>
                        </div>
                    </div>
                    <a class="license-card" href="https://github.com/gentpan/LitePic/issues" target="_blank" rel="noopener noreferrer">
                        <i class="fa-light fa-circle-question" aria-hidden="true"></i>
                        <div class="license-card-body">
                            <span class="license-card-label">反馈</span>
                            <span class="license-card-value">GitHub Issues</span>
                        </div>
                    </a>
                </div>

                <!-- 法律声明 -->
                <p class="license-note">
                    <i class="fa-light fa-circle-info" aria-hidden="true"></i>
                    <span>二次开发、分发或商用时，请保留版权与协议声明，并以仓库中的 LICENSE 文件为准。</span>
                </p>

                <!-- 官网入口 -->
                <a class="license-footer" href="https://litepic.io" target="_blank" rel="noopener noreferrer">
                    <span>访问官网 litepic.io</span>
                    <i class="fa-light fa-arrow-up-right-from-square" aria-hidden="true"></i>
                </a>
            </div>
        `;

        ImgEt.DialogManager.showCustomDialog('版权说明', content);
    });
}

document.addEventListener('DOMContentLoaded', initLicenseDialog);

/* =====================================================================
 * 图库卡片右键菜单
 * ---------------------------------------------------------------------
 *  - 只在 .img-card / .img-box 上拦截，其它区域走浏览器默认菜单
 *  - 菜单项按 data-* 标志位条件渲染：
 *      重新生成缩略图 / 复制原图链接 / 下载原图 / 下载 WebP / 下载 AVIF /
 *      下载缩略图 / 转换 AVIF（默认 WebP 时显示）/ 转换 WebP（默认 AVIF 时显示）
 *  - 点击菜单外、按 Esc、或滚动都会自动关闭
 * ===================================================================== */
(function() {
    let menuEl = null;

    function close() {
        if (menuEl) {
            menuEl.remove();
            menuEl = null;
        }
        document.removeEventListener('mousedown', onOutside, true);
        document.removeEventListener('keydown', onKey, true);
        window.removeEventListener('scroll', close, true);
        window.removeEventListener('resize', close, true);
    }

    function onOutside(e) {
        if (menuEl && !menuEl.contains(e.target)) close();
    }
    function onKey(e) {
        if (e.key === 'Escape') close();
    }

    // 触发下载 — 用 <a download> 同源文件直接保存到本地
    function download(url, filename) {
        if (!url) return;
        const a = document.createElement('a');
        a.href = url;
        a.download = filename || '';
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            window.ImgEt?.Utils?.showNotification?.('已复制到剪贴板', 'success');
        } catch {
            window.ImgEt?.Utils?.showNotification?.('复制失败', 'error');
        }
    }

    // 调 /api/v1/action 重新生成缩略图，成功后给 <img src> 加 cache-bust
    async function regenerateThumbnail(filename, card) {
        const csrf = window.CSRF_TOKEN || '';
        try {
            const res = await fetch('/api/v1/action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'regenerate_thumbnail', file: filename, csrf_token: csrf }),
            });
            const json = await res.json();
            if (json.status !== 'success') throw new Error(json.message || '生成失败');
            // 刷新可见缩略图：原 src + ?v=<时间戳> 强制 reload
            const img = card.querySelector('img');
            if (img) {
                const baseUrl = json.thumb_url || img.getAttribute('src');
                const sep = baseUrl.includes('?') ? '&' : '?';
                img.setAttribute('src', baseUrl + sep + 'v=' + Date.now());
            }
            card.dataset.thumbUrl = json.thumb_url || card.dataset.thumbUrl || '';
            card.dataset.hasThumb = '1';
            window.ImgEt?.Utils?.showNotification?.('缩略图已重新生成', 'success');
        } catch (e) {
            window.ImgEt?.Utils?.showNotification?.('缩略图生成失败：' + e.message, 'error');
        }
    }

    // 通用 action 调用 — 用于格式转换
    async function runAction(action, filename) {
        const csrf = window.CSRF_TOKEN || '';
        const labelMap = { webp: '转换 WebP', avif: '转换 AVIF', jpg: '转换 JPG', png: '转换 PNG' };
        const label = labelMap[action] || action;
        try {
            const res = await fetch('/api/v1/action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action, file: filename, csrf_token: csrf }),
            });
            const json = await res.json();
            if (json.status !== 'success') throw new Error(json.message || '操作失败');
            window.ImgEt?.Utils?.showNotification?.(label + ' 完成', 'success');
        } catch (e) {
            window.ImgEt?.Utils?.showNotification?.(label + ' 失败：' + e.message, 'error');
        }
    }

    // 构建菜单 HTML — items 是 [{icon, label, onClick} | {separator: true}]
    function build(items, x, y) {
        close(); // 之前的菜单先关掉
        menuEl = document.createElement('div');
        menuEl.className = 'img-context-menu';
        menuEl.innerHTML = items.map((it, i) => {
            if (it.separator) {
                return '<div class="img-context-menu__sep" aria-hidden="true"></div>';
            }
            return `<button type="button" class="img-context-menu__item" data-idx="${i}">
                <i class="fa-light ${it.icon}" aria-hidden="true"></i>
                <span>${it.label}</span>
            </button>`;
        }).join('');
        document.body.appendChild(menuEl);

        // 边缘检测 + 翻转
        const rect = menuEl.getBoundingClientRect();
        const vw = window.innerWidth, vh = window.innerHeight;
        const left = (x + rect.width  > vw - 8) ? Math.max(8, x - rect.width)  : x;
        const top  = (y + rect.height > vh - 8) ? Math.max(8, y - rect.height) : y;
        menuEl.style.left = left + 'px';
        menuEl.style.top  = top + 'px';

        // 绑定 item click
        menuEl.querySelectorAll('.img-context-menu__item').forEach((btn) => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx, 10);
                const item = items[idx];
                close();
                if (item && typeof item.onClick === 'function') {
                    item.onClick();
                }
            });
        });

        // 关闭事件
        setTimeout(() => {
            document.addEventListener('mousedown', onOutside, true);
            document.addEventListener('keydown', onKey, true);
            window.addEventListener('scroll', close, true);
            window.addEventListener('resize', close, true);
        }, 0);
    }

    document.addEventListener('contextmenu', (e) => {
        const card = e.target.closest('.img-card, .img-box');
        if (!card) return;          // 不在图片卡片上 → 让浏览器默认菜单接管
        e.preventDefault();          // 在卡片上 → 阻止默认菜单，弹自定义

        const ds = card.dataset;
        const filename = ds.filename || '';
        const url      = ds.url || '';
        const thumbUrl = ds.thumbUrl || '';
        const webpUrl  = ds.webpUrl || '';
        const avifUrl  = ds.avifUrl || '';
        const hasWebp  = ds.hasWebp === '1';
        const hasAvif  = ds.hasAvif === '1';
        const hasThumb = ds.hasThumb === '1';
        const canConvert = ds.canConvert === '1';
        const preferred = (ds.preferredFormat || 'webp').toLowerCase();
        const sourceExt = (filename.split('.').pop() || '').toLowerCase();

        const items = [
            { icon: 'fa-image', label: '重新生成缩略图', onClick: () => regenerateThumbnail(filename, card) },
            { separator: true },
            { icon: 'fa-link',  label: '复制原图链接',   onClick: () => copyToClipboard(url) },
            { separator: true },
            { icon: 'fa-download', label: '下载原图',     onClick: () => download(url, filename) },
        ];
        if (hasWebp && webpUrl) {
            items.push({ icon: 'fa-download', label: '下载 WebP', onClick: () => download(webpUrl, filename.replace(/\.[a-z0-9]+$/i, '.webp')) });
        }
        if (hasAvif && avifUrl) {
            items.push({ icon: 'fa-download', label: '下载 AVIF', onClick: () => download(avifUrl, filename.replace(/\.[a-z0-9]+$/i, '.avif')) });
        }
        if (hasThumb && thumbUrl) {
            items.push({ icon: 'fa-download', label: '下载缩略图', onClick: () => download(thumbUrl, 'thumb_' + filename) });
        }
        // 提供非默认偏好格式的转换入口。
        if (canConvert) {
            items.push({ separator: true });
            const labels = { webp: 'WebP', avif: 'AVIF', jpg: 'JPG', png: 'PNG' };
            const convertMap = {
                jpg: ['webp', 'avif', 'png'],
                jpeg: ['webp', 'avif', 'png'],
                png: ['webp', 'avif', 'jpg'],
                gif: ['webp', 'avif', 'jpg', 'png'],
                webp: ['jpg', 'png'],
                avif: ['jpg', 'png'],
                heic: ['webp', 'avif', 'jpg', 'png'],
                heif: ['webp', 'avif', 'jpg', 'png'],
            };
            const canTarget = (target) => (convertMap[sourceExt] || []).includes(target);
            ['webp', 'avif', 'jpg', 'png'].forEach((target) => {
                if (target !== preferred && canTarget(target)) {
                    items.push({ icon: 'fa-file-code', label: `转换 ${labels[target]}`, onClick: () => runAction(target, filename) });
                }
            });
        }

        build(items, e.clientX, e.clientY);
    });
})();

/* =====================================================================
 * 图库卡片文件名内联重命名
 * ---------------------------------------------------------------------
 *  - 双击 .img-name → 进入 contenteditable=true,全选当前文本
 *  - Enter → blur 触发保存
 *  - Esc → 恢复原文本后 blur,不保存
 *  - blur 时若内容变了则 POST /api/v1/action action=rename
 *  - 后端只改 images.original_name 列(对外展示名),不动磁盘文件名
 *  - 扩展名永远保留原始的(后端剥掉用户输入的后缀)
 *
 *  事件代理在 document 上挂,因为画廊卡片在 GalleryManager 刷新时会被
 *  替换。代理保证新卡片自动获得行为,不用每次 attach。
 * ===================================================================== */
(function() {
    document.addEventListener('dblclick', (e) => {
        const nameEl = e.target.closest?.('.img-card .img-name, .img-box .img-name');
        if (!nameEl) return;
        // 已经在编辑中 — 双击是用户在选词,别拦截。
        if (nameEl.getAttribute('contenteditable') === 'true') return;
        // 卡片必须在画廊页(有 admin 权限)。upload 页的 .img-box 也有 .img-name,
        // 但那里 show_select=false 不渲染 .img-info,所以不会命中 — 防御性检查留着。
        if (!nameEl.closest('.gallery, .upload-grid')) return;

        e.preventDefault();
        const originalDisplay = nameEl.textContent.trim();
        nameEl.dataset.originalText = originalDisplay;
        nameEl.setAttribute('contenteditable', 'true');
        nameEl.spellcheck = false;
        nameEl.focus();

        // 全选文本
        const range = document.createRange();
        range.selectNodeContents(nameEl);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    });

    document.addEventListener('keydown', (e) => {
        const nameEl = e.target?.closest?.('.img-name[contenteditable="true"]');
        if (!nameEl) return;
        if (e.key === 'Enter') {
            e.preventDefault();
            nameEl.blur(); // blur 触发保存
        } else if (e.key === 'Escape') {
            e.preventDefault();
            nameEl.textContent = nameEl.dataset.originalText || '';
            nameEl.dataset.cancelled = '1';
            nameEl.blur();
        }
    });

    // blur 不冒泡,但 focusout 冒泡。用 focusout 才能事件代理。
    document.addEventListener('focusout', async (e) => {
        const nameEl = e.target;
        if (!(nameEl instanceof HTMLElement)) return;
        if (!nameEl.matches?.('.img-name[contenteditable="true"]')) return;

        nameEl.removeAttribute('contenteditable');

        const cancelled = nameEl.dataset.cancelled === '1';
        delete nameEl.dataset.cancelled;

        const newText = (nameEl.textContent || '').trim();
        const oldText = nameEl.dataset.originalText || '';
        delete nameEl.dataset.originalText;

        // 取消 / 没动 / 清空 → 直接回滚显示
        if (cancelled || newText === '' || newText === oldText) {
            nameEl.textContent = oldText;
            return;
        }

        const card = nameEl.closest('.img-card, .img-box');
        const filename = card?.dataset?.filename || '';
        if (!filename) {
            nameEl.textContent = oldText;
            return;
        }

        try {
            const result = await ApiService.request('/api/v1/action', {
                action: 'rename',
                file: filename,
                new_name: newText,
                csrf_token: window.CSRF_TOKEN || ''
            }, { method: 'POST' });

            // 后端权威 — 用 display_name(已去扩展名)更新卡片显示,
            // 用 original_name(含扩展名)更新 title hover 提示。
            const display = (result && result.display_name) || newText;
            const fullName = (result && result.original_name) || display;
            nameEl.textContent = display;
            const row = nameEl.closest('.img-name-row');
            const named = row?.querySelector('.img-name');
            if (named) named.title = fullName;

            window.ImgEt?.Utils?.showNotification?.('已重命名为 ' + display, 'success');
        } catch (err) {
            nameEl.textContent = oldText;
            const msg = (err && err.message) ? err.message : '请稍后重试';
            window.ImgEt?.Utils?.showNotification?.('重命名失败:' + msg, 'error');
        }
    });
})();

/* ---------------------------
   主题切换（Footer 按钮逻辑）
   - 存储: localStorage.siteTheme = 'system'|'light'|'dark'
   --------------------------- */
(function() {
    const KEY = 'siteTheme';
    const html = document.documentElement;
    const themeGroup = document.querySelector('.theme-mode-toggle');
    const themeButtons = themeGroup ? Array.from(themeGroup.querySelectorAll('[data-theme-mode]')) : [];
    const themeMenuToggle = document.querySelector('[data-theme-menu-toggle]');
    const themeWrapper = themeMenuToggle?.closest('.theme-toggle-footer') || themeGroup?.closest('.theme-toggle-footer') || null;
    const themeFooter = themeWrapper?.closest('.site-footer') || null;
    const themeTriggerIcon = document.querySelector('[data-theme-trigger-icon]');
    const themeIconMap = {
        dark: 'fa-moon',
        light: 'fa-sun-bright',
        system: 'fa-display'
    };

    function applyTheme(mode) {
        const forceDark = document.body?.classList.contains('home-guest') === true;
        let applied = mode;
        if (forceDark) {
            applied = 'dark';
        } else if (mode === 'system') {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            applied = prefersDark ? 'dark' : 'light';
        }
        
        // 设置主题
        if (applied === 'dark') {
            html.setAttribute('data-theme', 'dark');
            html.style.backgroundColor = '#0c0c0c';
            html.style.colorScheme = 'dark';
        } else {
            html.removeAttribute('data-theme');
            html.style.backgroundColor = '#f8f9fa';
            html.style.colorScheme = 'light';
        }
        
        if (!forceDark) {
            localStorage.setItem(KEY, mode);
        }
        syncThemeButtons(forceDark ? 'dark' : mode);
    }

    function syncThemeButtons(mode) {
        if (!themeButtons.length) return;
        themeButtons.forEach((btn) => {
            const isActive = btn.getAttribute('data-theme-mode') === mode;
            btn.setAttribute('aria-checked', isActive ? 'true' : 'false');
            btn.classList.toggle('is-active', isActive);
        });
        if (themeTriggerIcon) {
            themeTriggerIcon.className = `fa-light ${themeIconMap[mode] || themeIconMap.system}`;
        }
    }

    function setThemeMenuOpen(open) {
        if (!themeWrapper || !themeMenuToggle) return;
        themeWrapper.classList.toggle('is-open', open);
        themeFooter?.classList.toggle('theme-menu-open', open);
        themeMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function initTheme() {
        const saved = localStorage.getItem(KEY) || 'system';
        applyTheme(saved);

        if (themeButtons.length) {
            themeButtons.forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const mode = btn.getAttribute('data-theme-mode') || 'system';
                    applyTheme(mode);
                    setThemeMenuOpen(false);
                    themeMenuToggle?.focus({ preventScroll: true });
                });
            });
        }

        if (themeMenuToggle && themeWrapper) {
            themeMenuToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                setThemeMenuOpen(!themeWrapper.classList.contains('is-open'));
            });

            document.addEventListener('click', (e) => {
                if (!themeWrapper.classList.contains('is-open')) return;
                if (themeWrapper.contains(e.target)) return;
                setThemeMenuOpen(false);
            });

            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                if (!themeWrapper.classList.contains('is-open')) return;
                setThemeMenuOpen(false);
                themeMenuToggle.focus({ preventScroll: true });
            });
        }

        // 键盘左右切换
        if (themeGroup) {
            themeGroup.addEventListener('keydown', (e) => {
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
                e.preventDefault();
                if (!themeButtons.length) return;
                const current = localStorage.getItem(KEY) || 'system';
                const currentIndex = Math.max(0, themeButtons.findIndex(btn => btn.getAttribute('data-theme-mode') === current));
                const delta = e.key === 'ArrowRight' ? 1 : -1;
                const nextIndex = (currentIndex + delta + themeButtons.length) % themeButtons.length;
                const nextMode = themeButtons[nextIndex].getAttribute('data-theme-mode') || 'system';
                applyTheme(nextMode);
            });
        }

        // 监听系统主题变化
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onSystemChange = () => {
            if (localStorage.getItem(KEY) === 'system') {
                applyTheme('system');
            }
        };
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onSystemChange);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onSystemChange);
        }
    }

    // DOMContentLoaded 时初始化
    document.addEventListener('DOMContentLoaded', () => {
        try {
            initTheme();
        } catch (e) {
            console.error('Theme initialization failed:', e);
        }
    });
})();

/* ---------------------------
   首页 footer 显示：无滚动页面中监听向下滚轮/触摸
   --------------------------- */
(function() {
    const body = document.body;
    if (!body || !body.classList.contains('home-guest')) return;

    let touchStartY = 0;
    const showFooter = () => {
        body.classList.add('home-footer-visible');
    };
    const hideFooter = () => {
        body.classList.remove('home-footer-visible');
    };

    window.addEventListener('wheel', (event) => {
        if (event.deltaY > 0) {
            showFooter();
        } else if (event.deltaY < 0) {
            hideFooter();
        }
    }, { passive: true });

    window.addEventListener('touchstart', (event) => {
        touchStartY = event.touches?.[0]?.clientY || 0;
    }, { passive: true });

    window.addEventListener('touchmove', (event) => {
        const currentY = event.touches?.[0]?.clientY || 0;
        if (touchStartY - currentY > 24) {
            showFooter();
            touchStartY = currentY;
        } else if (currentY - touchStartY > 24) {
            hideFooter();
            touchStartY = currentY;
        }
    }, { passive: true });

    const formatBytes = (bytes) => {
        const size = Number(bytes) || 0;
        if (size <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        const index = Math.min(units.length - 1, Math.floor(Math.log(size) / Math.log(1024)));
        const value = size / Math.pow(1024, index);
        const formatted = value % 1 === 0 ? String(value) : value.toFixed(1).replace(/\\.0$/, '');
        return `${formatted} ${units[index]}`;
    };

    const animateNumber = (el, target, formatter) => {
        const duration = 900;
        const startTime = performance.now();
        const endValue = Math.max(0, Number(target) || 0);
        const easeOut = (t) => 1 - Math.pow(1 - t, 3);

        const frame = (now) => {
            const progress = Math.min(1, (now - startTime) / duration);
            const current = Math.round(endValue * easeOut(progress));
            el.textContent = formatter(current);
            if (progress < 1) {
                requestAnimationFrame(frame);
            } else {
                el.textContent = formatter(endValue);
            }
        };

        el.textContent = formatter(0);
        requestAnimationFrame(frame);
    };

    const stats = document.querySelector('[data-home-stats]');
    if (stats) {
        stats.querySelectorAll('[data-count-to]').forEach((el) => {
            animateNumber(el, el.getAttribute('data-count-to'), (value) => value.toLocaleString());
        });
        stats.querySelectorAll('[data-size-to]').forEach((el) => {
            animateNumber(el, el.getAttribute('data-size-to'), formatBytes);
        });
    }
})();

// 上传页面的图片卡片交互（仅处理 copy，delete 由 GalleryManager.initUploadPage 统一处理）
document.addEventListener('DOMContentLoaded', () => {
    const uploadGrid = document.querySelector('.upload-grid');
    if (!uploadGrid) return;

    uploadGrid.addEventListener('click', async e => {
        const btn = e.target.closest('.action-btn');
        if (!btn || !btn.classList.contains('copy-btn')) return;
        
        const imgBox = btn.closest('.img-box');
        if (!imgBox) return;

        const imgEl = imgBox.querySelector('img');
        const imgUrl = imgBox.dataset.url || imgEl?.dataset?.originalUrl || imgEl?.src || '';

        try {
            await navigator.clipboard.writeText(imgUrl);
            ImgEt.Utils.showNotification('图片链接已复制', 'success');
        } catch (error) {
            ImgEt.Utils.showNotification('复制失败', 'error'); 
        }
    });
});


/* ===== auth.js ===== */
/**
 * 登录验证管理模块
 */
window.ApiManager = {
    init() {

        // 检查必要组件
        if (!window.ImgEt || !window.ImgEt.Utils) {
            console.error('Core utilities not loaded');
            return;
        }

        // 获取所有需要的 DOM 元素
        const elements = {
            panel: document.getElementById('loginPanel'),
            input: document.getElementById('apiKey'),
            submit: document.querySelector('.login-submit'),
            loginButtons: Array.from(document.querySelectorAll('.login-btn')),
            logoutButtons: Array.from(document.querySelectorAll('.logout-btn'))
        };

        const hasLoginMode = elements.loginButtons.length > 0;
        const hasLogoutMode = elements.logoutButtons.length > 0;

        if (!hasLoginMode && !hasLogoutMode) {
            return;
        }

        if (hasLoginMode) {
            const requiredElements = ['panel', 'input', 'submit'];
            const missingElements = requiredElements.filter(key => !elements[key]);
            if (missingElements.length > 0) {
                console.warn(`Missing elements: ${missingElements.join(', ')}`);
                return;
            }
        }

        // 存储元素引用
        this.elements = elements;
        this.activeLoginButton = null;
        this.activeLoginRedirect = '';

        // 绑定事件
        this.bindEvents();
    },

    bindEvents() {
        const { panel, input, submit, loginButtons, logoutButtons } = this.elements;

        // 回车键提交
        if (input) {
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.login();
                }
            });
        }

        // 提交按钮点击
        if (submit) {
            submit.addEventListener('click', () => {
                this.login();
            });
        }

        // Passkey 登录按钮
        const passkeyBtn = document.querySelector('.login-passkey-btn');
        if (passkeyBtn) {
            passkeyBtn.addEventListener('click', () => {
                this.passkeyLogin();
            });
        }

        // 绑定登录按钮（可有多个）
        loginButtons.forEach((button) => {
            button.dataset.authBound = '1';
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', (e) => {
                e.preventDefault();
                if (button.dataset.authenticated === '1' && button.dataset.loginRedirect) {
                    window.location.href = button.dataset.loginRedirect;
                    return;
                }
                if (button.dataset.loginMessage) {
                    ImgEt.Utils.showNotification(button.dataset.loginMessage, 'info', { variant: 'auth' });
                }
                this.toggleLoginPanel(button);
            });
        });

        // 绑定退出按钮（可有多个）
        logoutButtons.forEach((button) => {
            button.dataset.authBound = '1';
            button.addEventListener('click', (e) => {
                e.preventDefault();
                this.logout();
            });
        });

        // 当前用于登录面板外部点击关闭的“主登录按钮”
        const primaryLoginButton = loginButtons[0] || null;
        this.primaryLoginButton = primaryLoginButton;

        if (panel && primaryLoginButton) {
            // 点击外部关闭下拉面板
            document.addEventListener('click', (e) => {
                if (!panel.classList.contains('active')) return;
                const clickedClose = e.target.closest('[data-login-close]');
                if (clickedClose && panel.contains(clickedClose)) {
                    this.hideLoginPanel();
                    return;
                }
                if (panel.dataset.modal === '1' && e.target === panel) {
                    this.hideLoginPanel();
                    return;
                }
                const clickedInsidePanel = panel.contains(e.target);
                const clickedButton = loginButtons.some((button) => button.contains(e.target));
                if (!clickedInsidePanel && !clickedButton) {
                    this.hideLoginPanel();
                }
            });
        }

        // ESC 键关闭面板
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && panel && panel.classList.contains('active')) {
                this.hideLoginPanel();
            }
        });
    },

    toggleLoginPanel(triggerButton = null) {
        const panel = document.getElementById('loginPanel');
        if (!panel) return;
        const isSameTrigger = triggerButton && this.activeLoginButton === triggerButton;
        if (panel.classList.contains('active') && (!triggerButton || isSameTrigger)) {
            this.hideLoginPanel();
        } else {
            this.showLoginPanel(triggerButton);
        }
    },

    // 显示登录下拉
    showLoginPanel(triggerButton = null) {
        const panel = document.getElementById('loginPanel');
        const input = document.getElementById('apiKey');
        const button = triggerButton || this.primaryLoginButton || document.querySelector('.login-btn');
        
        if (!panel || !input) return;
        
        this.activeLoginButton = button;
        this.activeLoginRedirect = button?.dataset?.loginRedirect || '';
        panel.dataset.activeRedirect = this.activeLoginRedirect;
        panel.classList.add('active');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('login-modal-open');
        this.elements?.loginButtons?.forEach((loginButton) => {
            loginButton.setAttribute('aria-expanded', loginButton === button ? 'true' : 'false');
        });
        if (button) button.setAttribute('aria-expanded', 'true');
        input.value = ''; // 清空输入
        setTimeout(() => input.focus(), 100); // 延迟聚焦
    },

    // 隐藏登录下拉
    hideLoginPanel() {
        const panel = document.getElementById('loginPanel');
        if (!panel) return;
        
        panel.classList.remove('active');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('login-modal-open');
        delete panel.dataset.activeRedirect;
        this.elements?.loginButtons?.forEach((loginButton) => {
            loginButton.setAttribute('aria-expanded', 'false');
        });
        this.activeLoginButton = null;
        this.activeLoginRedirect = '';
    },

    getLoginSuccessRedirect() {
        const panel = document.getElementById('loginPanel');
        return this.activeLoginRedirect || panel?.dataset?.activeRedirect || panel?.dataset?.successRedirect || this.primaryLoginButton?.dataset?.loginRedirect || '';
    },

    finishLogin(opts = {}) {
        // 仍在用默认密码 → 强制弹出改密窗口，跳过自动跳转
        if (opts && opts.mustChangePassword) {
            this.hideLoginPanel();
            this.openChangePasswordModal({
                forced: true,
                currentPassword: opts.currentPassword || '',
                onSuccess: () => {
                    const redirect = this.getLoginSuccessRedirect();
                    if (redirect) window.location.href = redirect;
                    else window.location.reload();
                },
            });
            return;
        }

        const redirect = this.getLoginSuccessRedirect();
        this.hideLoginPanel();
        setTimeout(() => {
            if (redirect) {
                window.location.href = redirect;
            } else {
                window.location.reload();
            }
        }, 1000);
    },

    /**
     * 强制 / 主动 修改密码弹窗。
     * forced=true 时：用户不能跳过（无 X、无 ESC、无外部点击），必须改密成功后才放行。
     * 当前密码已知（刚登录拿到）则预填。
     */
    openChangePasswordModal(opts = {}) {
        const forced = !!opts.forced;
        const currentPassword = opts.currentPassword || '';
        const onSuccess = typeof opts.onSuccess === 'function' ? opts.onSuccess : null;

        // 如果已经存在则复用
        let overlay = document.getElementById('changePasswordOverlay');
        if (overlay) overlay.remove();

        overlay = document.createElement('div');
        overlay.id = 'changePasswordOverlay';
        overlay.className = 'change-password-overlay' + (forced ? ' is-forced' : '');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'changePasswordTitle');
        overlay.innerHTML = `
            <div class="change-password-dialog" role="document">
                <header class="change-password-header" id="changePasswordTitle">
                    <i class="fa-light fa-shield-keyhole" aria-hidden="true"></i>
                    <span>${forced ? '请修改默认密码' : '修改管理员密码'}</span>
                </header>
                ${forced ? '<p class="change-password-notice">检测到您仍在使用初始默认密码 <code>12345678</code>。为了账户安全，请立即设置一个新的强密码。</p>' : ''}
                <form class="change-password-form" novalidate>
                    <label class="change-password-field">
                        <span>当前密码</span>
                        <input type="password" name="currentPassword" autocomplete="current-password" value="${currentPassword.replace(/"/g, '&quot;')}" ${currentPassword ? 'readonly' : 'required'}>
                    </label>
                    <label class="change-password-field">
                        <span>新密码（至少 8 位）</span>
                        <input type="password" name="newPassword" autocomplete="new-password" minlength="8" required>
                    </label>
                    <label class="change-password-field">
                        <span>确认新密码</span>
                        <input type="password" name="confirmPassword" autocomplete="new-password" minlength="8" required>
                    </label>
                    <div class="change-password-actions">
                        ${forced ? '' : '<button type="button" class="change-password-cancel">取消</button>'}
                        <button type="submit" class="change-password-submit">
                            <i class="fa-light fa-check" aria-hidden="true"></i>
                            <span>保存新密码</span>
                        </button>
                    </div>
                    <p class="change-password-msg" role="status" aria-live="polite"></p>
                </form>
            </div>
        `;
        document.body.appendChild(overlay);
        document.body.classList.add('login-modal-open');

        const dialog = overlay.querySelector('.change-password-dialog');
        const form = overlay.querySelector('.change-password-form');
        const submitBtn = overlay.querySelector('.change-password-submit');
        const cancelBtn = overlay.querySelector('.change-password-cancel');
        const msg = overlay.querySelector('.change-password-msg');
        const newPwdInput = form.querySelector('input[name="newPassword"]');

        const close = () => {
            overlay.remove();
            document.body.classList.remove('login-modal-open');
        };

        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => close());
        }

        // 强制模式下点击外层 / ESC 都不关闭
        if (!forced) {
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) close();
            });
            document.addEventListener('keydown', function escClose(e) {
                if (e.key === 'Escape' && document.getElementById('changePasswordOverlay')) {
                    close();
                    document.removeEventListener('keydown', escClose);
                }
            });
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            msg.textContent = '';
            msg.className = 'change-password-msg';

            const fd = new FormData(form);
            const payload = {
                action: 'change_password',
                currentPassword: String(fd.get('currentPassword') || '').trim(),
                newPassword: String(fd.get('newPassword') || '').trim(),
                confirmPassword: String(fd.get('confirmPassword') || '').trim(),
            };

            if (payload.newPassword.length < 8) {
                msg.textContent = '新密码至少 8 位';
                msg.classList.add('is-error');
                return;
            }
            if (payload.newPassword !== payload.confirmPassword) {
                msg.textContent = '两次输入的新密码不一致';
                msg.classList.add('is-error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-light fa-spinner-third fa-spin" aria-hidden="true"></i> <span>保存中...</span>';
            try {
                const resp = await fetch('/api/auth.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const data = await resp.json().catch(() => ({}));
                if (!resp.ok || data.status !== 'success') {
                    throw new Error(data.message || `请求失败 (${resp.status})`);
                }
                // Password change rotates ADMIN_SESSION_SECRET → CSRF HMAC changes.
                if (typeof data.csrf_token === 'string' && data.csrf_token !== '') {
                    window.CSRF_TOKEN = data.csrf_token;
                    document.querySelectorAll('input[name="csrf_token"]').forEach((el) => {
                        el.value = data.csrf_token;
                    });
                }
                msg.textContent = '密码已修改';
                msg.classList.add('is-success');
                ImgEt.Utils.showNotification('密码修改成功', 'success', { variant: 'auth' });
                setTimeout(() => {
                    close();
                    if (onSuccess) onSuccess();
                }, 600);
            } catch (err) {
                console.error('Change password error:', err);
                msg.textContent = err.message || '修改失败';
                msg.classList.add('is-error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-light fa-check" aria-hidden="true"></i> <span>保存新密码</span>';
            }
        });

        // 聚焦第一个空输入
        const firstEmpty = form.querySelector('input:not([readonly])');
        if (firstEmpty) setTimeout(() => firstEmpty.focus(), 100);
    },

    // 登录处理
    async login() {
        const input = document.getElementById('apiKey');
        const submit = document.querySelector('.login-submit');
        
        if (!input || !submit) {
            ImgEt.Utils.showNotification('系统错误', 'error', { variant: 'auth' });
            return;
        }

        const apiKey = input.value.trim();
        
        if (!apiKey) {
            ImgEt.Utils.showNotification('请输入API Key', 'error', { variant: 'auth' });
            input.focus();
            return;
        }

        // 禁用提交按钮
        submit.disabled = true;
        submit.innerHTML = '<i class="fa-light fa-spinner-third fa-spin"></i> 登录中...';

        try {
            const response = await fetch('/api/auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ apiKey })
            });

            let data = null;
            try {
                data = await response.json();
            } catch (e) {
                // Ignore parse errors and fallback to status text below.
            }

            if (!response.ok) {
                throw new Error(data?.message || `请求失败 (${response.status})`);
            }

            if (!data) {
                throw new Error('服务器返回了无效的响应');
            }

            // 修正：检查 data.status 是否为 'success'，而不是 data.success
            if (data.status === 'success') {
                if (data.must_change_password) {
                    ImgEt.Utils.showNotification('登录成功，请立即修改默认密码', 'warning', { variant: 'auth' });
                    this.finishLogin({ mustChangePassword: true, currentPassword: apiKey });
                } else {
                    ImgEt.Utils.showNotification('登录成功', 'success', { variant: 'auth' });
                    this.finishLogin();
                }
            } else {
                throw new Error(data.message || 'API Key 无效');
            }
        } catch (error) {
            console.error('Login error:', error);
            ImgEt.Utils.showNotification(error.message || '登录失败，请重试', 'error', { variant: 'auth' });
            input.focus();
        } finally {
            // 恢复提交按钮
            submit.disabled = false;
            submit.innerHTML = '<i class="fa-light fa-right-to-bracket"></i> 登录';
        }
    },

    // Passkey 登录
    async passkeyLogin() {
        if (!window.PublicKeyCredential) {
            ImgEt.Utils.showNotification('您的浏览器不支持 Passkey', 'error', { variant: 'auth' });
            return;
        }

        try {
            const res = await fetch('/api/passkey.php?action=auth_options');
            const data = await res.json();
            if (data.status !== 'success') {
                throw new Error(data.message || '获取认证选项失败');
            }
            const options = data;

            // 将 Base64URL 转换为 ArrayBuffer
            options.challenge = this.base64UrlToBuffer(options.challenge);
            if (options.allowCredentials) {
                options.allowCredentials.forEach(cred => {
                    cred.id = this.base64UrlToBuffer(cred.id);
                });
            }

            const credential = await navigator.credentials.get({ publicKey: options });
            if (!credential) {
                throw new Error('用户取消了认证');
            }

            const verifyRes = await fetch('/api/passkey.php?action=auth_verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    credentialId: this.bufferToBase64Url(credential.rawId),
                    authenticatorData: this.bufferToBase64Url(credential.response.authenticatorData),
                    clientDataJSON: this.bufferToBase64Url(credential.response.clientDataJSON),
                    signature: this.bufferToBase64Url(credential.response.signature)
                })
            });

            const verifyData = await verifyRes.json();
            if (verifyData.status === 'success') {
                if (verifyData.must_change_password) {
                    ImgEt.Utils.showNotification('Passkey 登录成功，请立即修改默认管理员密码', 'warning', { variant: 'auth' });
                    this.finishLogin({ mustChangePassword: true });
                } else {
                    ImgEt.Utils.showNotification('Passkey 登录成功', 'success', { variant: 'auth' });
                    this.finishLogin();
                }
            } else {
                throw new Error(verifyData.message || 'Passkey 验证失败');
            }
        } catch (error) {
            console.error('Passkey login error:', error);
            ImgEt.Utils.showNotification(error.message || 'Passkey 登录失败', 'error', { variant: 'auth' });
        }
    },

    base64UrlToBuffer(base64url) {
        const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        const pad = 4 - (base64.length % 4);
        const padded = pad !== 4 ? base64 + '='.repeat(pad) : base64;
        const binary = atob(padded);
        const buffer = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            buffer[i] = binary.charCodeAt(i);
        }
        return buffer.buffer;
    },

    bufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    },

    // 退出登录
    async logout() {
        try {
            const response = await fetch('/api/auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'logout'
                })
            });

            let data = null;
            try {
                data = await response.json();
            } catch (e) {
                // Ignore parse errors and fallback to status text below.
            }

            if (!response.ok) {
                throw new Error(data?.message || `请求失败 (${response.status})`);
            }
            
            // 只检查服务器响应，不再手动操作 cookie
            if (data.status === 'success') {
                ImgEt.Utils.showNotification('已退出登录', 'success', { variant: 'auth' });
                
                // 延迟跳转
                setTimeout(() => {
                    // 跳转到首页，服务器会确保 cookie 已被清除
                    window.location.href = '/'; 
                }, 1000);
            } else {
                throw new Error(data.message || '退出失败');
            }
        } catch (error) {
            console.error('Logout error:', error);
            ImgEt.Utils.showNotification(error.message || '退出失败', 'error', { variant: 'auth' });
        }
    }
};

// 等待页面加载完成后再初始化
document.addEventListener('DOMContentLoaded', () => {
    // 确保 ImgEt 和工具类已加载
    if (!window.ImgEt || !window.ImgEt.Utils) {
        console.error('Required dependencies not loaded');
        return;
    }
    window.ApiManager.init();
});

window.addEventListener('load', () => {
    // 检查是否已经退出
    const params = new URLSearchParams(window.location.search);
    if (params.get('logout') === 'success') {
        // 清除 URL 参数
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});

// 认证按钮全局兜底：防止局部初始化失败导致登录/退出按钮无响应
document.addEventListener('click', async (e) => {
    const activePanel = document.getElementById('loginPanel');
    const closeBtn = e.target.closest('[data-login-close]');
    if (closeBtn && activePanel && activePanel.contains(closeBtn)) {
        e.preventDefault();
        if (window.ApiManager && typeof window.ApiManager.hideLoginPanel === 'function') {
            window.ApiManager.hideLoginPanel();
        } else {
            activePanel.classList.remove('active');
            activePanel.setAttribute('aria-hidden', 'true');
            delete activePanel.dataset.activeRedirect;
            document.body.classList.remove('login-modal-open');
        }
        return;
    }
    if (activePanel?.dataset?.modal === '1' && activePanel.classList.contains('active') && e.target === activePanel) {
        if (window.ApiManager && typeof window.ApiManager.hideLoginPanel === 'function') {
            window.ApiManager.hideLoginPanel();
        } else {
            activePanel.classList.remove('active');
            activePanel.setAttribute('aria-hidden', 'true');
            delete activePanel.dataset.activeRedirect;
            document.body.classList.remove('login-modal-open');
        }
        return;
    }

    const logoutBtn = e.target.closest('.logout-btn');
    if (logoutBtn && logoutBtn.dataset.authBound !== '1') {
        e.preventDefault();
        if (window.ApiManager && typeof window.ApiManager.logout === 'function') {
            window.ApiManager.logout();
            return;
        }
        try {
            const response = await fetch('/api/auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'logout' })
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok && data?.status === 'success') {
                window.location.href = '/';
            } else {
                window.ImgEt?.Utils?.showNotification?.(data?.message || '退出失败', 'error', { variant: 'auth' });
            }
        } catch (_) {
            window.ImgEt?.Utils?.showNotification?.('退出失败', 'error', { variant: 'auth' });
        }
        return;
    }

    const authToastBtn = e.target.closest('.auth-toast-btn');
    if (authToastBtn) {
        e.preventDefault();
        const message = authToastBtn.dataset.authMessage || '登录后操作';
        window.ImgEt?.Utils?.showNotification?.(message, 'info', { variant: 'auth' });
        return;
    }

    const loginBtn = e.target.closest('.login-btn');
    if (loginBtn && loginBtn.dataset.authBound !== '1') {
        e.preventDefault();
        if (loginBtn.dataset.authenticated === '1' && loginBtn.dataset.loginRedirect) {
            window.location.href = loginBtn.dataset.loginRedirect;
            return;
        }
        if (loginBtn.dataset.loginMessage) {
            window.ImgEt?.Utils?.showNotification?.(loginBtn.dataset.loginMessage, 'info', { variant: 'auth' });
        }
        const panel = document.getElementById('loginPanel');
        if (window.ApiManager && typeof window.ApiManager.toggleLoginPanel === 'function') {
            window.ApiManager.toggleLoginPanel(loginBtn);
            return;
        }
        if (panel) {
            panel.classList.toggle('active');
            panel.setAttribute('aria-hidden', panel.classList.contains('active') ? 'false' : 'true');
            if (panel.classList.contains('active')) {
                panel.dataset.activeRedirect = loginBtn.dataset.loginRedirect || '';
            } else {
                delete panel.dataset.activeRedirect;
            }
            document.body.classList.toggle('login-modal-open', panel.classList.contains('active'));
        }
    }
}, false);


/* ===== gallery.js ===== */
/**
 * LitePic - 图库核心脚本（重写）
 */

window.ImgEt = window.ImgEt || {};

/**
 * ApiService - 与后端交互的封装
 */
class ApiService {
    static async request(endpoint, params = {}, options = {}) {
        const url = new URL(endpoint, window.location.origin);
        const method = (options.method || 'GET').toUpperCase();

        if (method === 'GET') {
            Object.entries(params).forEach(([k, v]) => {
                if (v !== undefined && v !== null) url.searchParams.append(k, String(v));
            });
        }

        const defaultOptions = {
            method: method,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-cache'
        };

        if (method === 'POST' && !options.body) {
            const body = new URLSearchParams();
            Object.entries(params).forEach(([k, v]) => {
                if (v !== undefined && v !== null) body.append(k, String(v));
            });
            defaultOptions.headers['Content-Type'] = 'application/x-www-form-urlencoded';
            defaultOptions.body = body.toString();
        }

        const res = await fetch(url.toString(), { ...defaultOptions, ...options });
        if (!res.ok) {
            const respText = await res.text().catch(() => '');
            console.error('API 请求 URL:', url.toString());
            console.error('API 响应文本:', respText);
            let msg = `请求失败 (${res.status})`;
            try {
                const data = JSON.parse(respText);
                if (data?.message) msg = data.message;
            } catch (_) {}
            throw new Error(msg);
        }

        // 尝试解析 JSON，若失败抛出友好错误
        try {
            return await res.json();
        } catch (err) {
            const text = await res.text().catch(() => '');
            console.error('解析 JSON 失败，响应文本：', text);
            throw new Error('服务器返回了非 JSON 响应');
        }
    }

    static async getCardTemplate(data, type = 'gallery') {
        const body = new URLSearchParams({
            img: data.filename,
            type: type,
            info: JSON.stringify({
                filename: data.filename,
                original_name: data.original_name || data.filename,
                size: data.size,
                dimensions: data.dimensions || '',
                format: data.format || '',
                time: Date.now(),
                url: data.url,
                thumb_url: data.thumb_url || data.thumbnail_url || data.url
            })
        });

        // 使用版本化 action 端点输出卡片 HTML
        const res = await fetch('/api/v1/action?action=render_card', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'text/html'
            },
            body: body.toString()
        });

        if (!res.ok) {
            const txt = await res.text().catch(() => '');
            console.error('获取卡片模板失败，响应：', txt);
            throw new Error(`获取卡片模板失败: ${res.status}`);
        }

        const html = await res.text();
        // 额外记录返回以便调试
        if (!html || html.includes('<b>Error</b>') || html.includes('<br />')) {
            console.error('无效卡片模板响应：', html);
            throw new Error('服务器返回了无效的卡片模板');
        }
        return html;
    }
}

function getImageCardSelector(filename) {
    if (typeof filename !== 'string' || filename === '') {
        return '';
    }
    const escaped = (window.CSS && typeof window.CSS.escape === 'function')
        ? window.CSS.escape(filename)
        : filename.replace(/["\\]/g, '\\$&');
    return `.img-card[data-filename="${escaped}"], .img-box[data-filename="${escaped}"]`;
}

/**
 * BaseProcessor - 处理状态基类
 */
class BaseProcessor {
    static _isProcessing = false;

    static isProcessing() { return this._isProcessing; }
    static startProcessing() { this._isProcessing = true; }
    static endProcessing() { this._isProcessing = false; }
}

/**
 * DeleteManager - 删除相关逻辑
 */
class DeleteManager extends BaseProcessor {
    static #updateDelay = 300;

    static handleDelete(target) {
        if (this.isProcessing() || !target) return;

        const isBatch = Array.isArray(target);
        const message = isBatch
            ? `删除后，选中的 ${target.length} 张图片将不可恢复，相关图片链接也将失效。确认删除吗？`
            : `删除后，该图片将不可恢复，相关图片链接也将失效。确认删除吗？`;
        const title = isBatch ? '批量删除图片' : '永久删除图片';

        ImgEt.DialogManager.showConfirmDialog(title, message, async () => {
            if (isBatch) {
                await BatchProcessor.process('delete', target);
            } else {
                await this.processSingle(target);
            }
        }, {
            danger: true,
            confirmText: '删除'
        });
    }

    static async processSingle(filename, suppressLoad = false, notifyOptions = {}) {
        this.startProcessing();
        const shouldNotifySuccess = notifyOptions.notifySuccess !== false;
        const shouldNotifyError = notifyOptions.notifyError !== false;
        try {
            const imgCard = document.querySelector(getImageCardSelector(filename));
            const isGalleryPage = !!document.querySelector('.gallery-shell');
            const isUploadPage = !!document.querySelector('.upload-grid') && !isGalleryPage;

            // 2. 执行删除请求
            await ApiService.request('/api/v1/action', {
                action: 'delete',
                file: filename,
                csrf_token: window.CSRF_TOKEN || ''
            }, { method: 'POST' });

            if (isGalleryPage && !suppressLoad) {
                if (shouldNotifySuccess) {
                    ImgEt.Utils.showNotification('删除成功', 'success');
                }
                try {
                    await GalleryManager.refreshCurrentPage();
                } catch (refreshError) {
                    console.warn('刷新图库失败:', refreshError);
                    ImgEt.Utils.showNotification('图片已删除，刷新图库失败，请手动刷新页面', 'warning');
                }
                return;
            }

            if (isUploadPage && !suppressLoad) {
                if (shouldNotifySuccess) {
                    ImgEt.Utils.showNotification('删除成功', 'success');
                }
                try {
                    await GalleryManager.refreshRecentUploads();
                } catch (refreshError) {
                    console.warn('刷新最近上传失败:', refreshError);
                    ImgEt.Utils.showNotification('图片已删除，最近上传刷新失败，请手动刷新页面', 'warning');
                }
                return;
            }
            
            // 3. 更新总数显示
            const totalCountEl = document.querySelector('.total-count');
            if (totalCountEl) {
                const currentTotal = parseInt(totalCountEl.dataset.total || '0', 10);
                const newTotal = Math.max(0, currentTotal - 1);
                totalCountEl.dataset.total = String(newTotal);
                totalCountEl.textContent = `(共 ${newTotal} 张图片)`;
            }

            // 4. 移除当前卡片
            imgCard.classList.add('deleting');
            
            if (shouldNotifySuccess) {
                ImgEt.Utils.showNotification('删除成功', 'success');
            }
            
            // 6. 非图库/最近上传区域才走轻量 DOM 移除；需要排序窗口的页面
            // 会在前面直接重拉服务端片段，避免 DOM 数量推断导致错位。
            setTimeout(async () => {
                this.#removeImageElement(filename, true);
            }, 300);

        } catch (error) {
            if (shouldNotifyError) {
                ImgEt.Utils.showNotification(`删除失败: ${error.message}`, 'error');
            }
            throw error;
        } finally {
            this.endProcessing();
        }
    }

    // 接收 suppressLoad，若为 true 则不在单次删除时触发补位
    static async #removeImageElement(filename, suppressLoad = false) {
        const selector = getImageCardSelector(filename);
        const imgCard = document.querySelector(selector);
        if (!imgCard) return;

        imgCard.classList.add('deleting');
        setTimeout(async () => {
            imgCard.remove();
            GalleryManager.updateImageCount();

            // 单图删除补位 — 始终走 #loadNewImages(1) 拿一张新图增量
            // 插入到末尾。
            //
            // 旧逻辑会在 .gallery-shell 存在时调 refreshCurrentPage()，
            // 那个方法会重新 fetch 整页 HTML、replace shell DOM、所有
            // <img> 重新解码、所有事件 listener 重新绑定 — 视觉上等于
            // 整页刷新一次，500ms-1s 的卡顿。
            //
            // 改成 #loadNewImages(1) 后只新建一个卡片节点 + 装绑它的
            // 事件，跟首页 / 其它页面保持一致的丝滑增量行为。
            if (!suppressLoad) {
                try {
                    await this.#loadNewImages(1);
                } catch (e) { /* already handled inside */ }
            }
        }, this.#updateDelay);
    }

    static async #loadNewImages(count = 1) {
        try {
            const gallery = document.querySelector('.gallery');
            const uploadGrid = document.querySelector('.upload-grid');
            const isGallery = !!gallery;
            const isUpload = !!uploadGrid;

            if (!isGallery && !isUpload) {
                console.warn('No gallery or upload grid found');
                return;
            }

            const currentCount = isGallery
                ? document.querySelectorAll('.img-card').length
                : uploadGrid.querySelectorAll('.img-box').length;

            const response = await ApiService.request('/api/v1/action', {
                action: 'get_next_image',
                current_count: currentCount,
                count: count
            });

            if (response?.status === 'success') {
                const imgs = response.images || [];
                if (imgs.length === 0) {
                    console.warn('No more images to load');
                    return;
                }

                if (isGallery) {
                    for (const image of imgs) {
                        try {
                            const cardHtml = await ApiService.getCardTemplate(image);
                            gallery.insertAdjacentHTML('beforeend', cardHtml);
                            const newCard = gallery.lastElementChild;
                            if (newCard) {
                                GalleryManager.initNewCard(newCard);
                                // 加 CSS 类触发淡入动画 — 跟删除的淡出对称，
                                // 视觉上像"位置被另一张图自然填上"
                                newCard.classList.add('appearing');
                                requestAnimationFrame(() => {
                                    requestAnimationFrame(() => newCard.classList.remove('appearing'));
                                });
                            }
                        } catch (errCard) {
                            console.error('插入新卡片失败:', errCard);
                        }
                    }
                    GalleryManager.updateImageCount();
                } else if (isUpload) {
                    for (const image of imgs) {
                        try {
                            const cardHtml = await ApiService.getCardTemplate(image, 'recent');
                            uploadGrid.insertAdjacentHTML('beforeend', cardHtml);
                            const newCard = uploadGrid.lastElementChild;
                            if (newCard) {
                                GalleryManager.initNewCard(newCard);
                                newCard.classList.add('appearing');
                                requestAnimationFrame(() => {
                                    requestAnimationFrame(() => newCard.classList.remove('appearing'));
                                });
                            }
                        } catch (errCard) {
                            console.error('插入新卡片失败:', errCard);
                        }
                    }
                }
            } else {
                throw new Error(response?.message || '加载图片失败');
            }
        } catch (error) {
            console.error('Failed to load new images:', error);
            ImgEt.Utils.showNotification(`加载图片失败: ${error.message}`, 'error');
            throw error;
        }
    }

    // 公共调用接口（批量调用使用）
    static async loadNewImages(count = 1) {
        return await this.#loadNewImages(count);
    }
}

/**
 * ImageProcessor - 单图处理 (压缩 / WebP)
 */
class ImageProcessor extends BaseProcessor {
    static async processSingleCompress(filename, imgCard, options = {}) {
        if (this.isProcessing()) {
            ImgEt.Utils.showNotification('有操作正在进行中', 'warning');
            return;
        }
        this.startProcessing();
        const btn = imgCard?.querySelector?.('.compress-btn') || null;
        GalleryManager.setButtonLoadingState(btn, true, 'compress');

        try {
            const data = await ApiService.request('/api/v1/action', { action: 'compress', file: filename, csrf_token: window.CSRF_TOKEN || '' }, { method: 'POST' });
            const sizeText = imgCard.querySelector('.img-size-value') || imgCard.querySelector('.img-size');
            if (sizeText && data?.size_text) sizeText.textContent = data.size_text;
            if (options.dialog !== false) {
                this.#showCompressResult(data, imgCard);
            }
            if (options.notify !== false) {
                this.#showCompressToast(data);
            }
            return data;
        } catch (error) {
            if (options.notifyError !== false) {
                ImgEt.Utils.showNotification(`压缩失败: ${error.message}`, 'error');
            }
            throw error;
        } finally {
            GalleryManager.setButtonLoadingState(btn, false, 'compress');
            this.endProcessing();
        }
    }

    static async processSingleWebP(filename, imgCard, options = {}) {
        if (this.isProcessing()) {
            ImgEt.Utils.showNotification('有操作正在进行中', 'warning');
            return;
        }
        this.startProcessing();
        const btn = imgCard?.querySelector?.('.webp-btn') || null;
        GalleryManager.setButtonLoadingState(btn, true, 'webp');

        try {
            const data = await ApiService.request('/api/v1/action', { action: 'webp', file: filename, csrf_token: window.CSRF_TOKEN || '' }, { method: 'POST' });
            if (options.dialog !== false) {
                this.#showConvertResult(data, 'WebP');
            }
            await this.#addConvertedCard(data, imgCard);
            if (options.notify !== false) {
                this.#showConvertToast(data, 'WebP');
            }
            return data;
        } catch (error) {
            if (options.notifyError !== false) {
                ImgEt.Utils.showNotification(`转换失败: ${error.message}`, 'error');
            }
            throw error;
        } finally {
            GalleryManager.setButtonLoadingState(btn, false, 'webp');
            this.endProcessing();
        }
    }

    static async processSingleAvif(filename, imgCard, options = {}) {
        if (this.isProcessing()) {
            ImgEt.Utils.showNotification('有操作正在进行中', 'warning');
            return;
        }
        this.startProcessing();
        const btn = imgCard?.querySelector?.('.avif-btn') || null;
        GalleryManager.setButtonLoadingState(btn, true, 'avif');

        try {
            const data = await ApiService.request('/api/v1/action', { action: 'avif', file: filename, csrf_token: window.CSRF_TOKEN || '' }, { method: 'POST' });
            if (options.dialog !== false) {
                this.#showConvertResult(data, 'AVIF');
            }
            await this.#addConvertedCard(data, imgCard);
            if (options.notify !== false) {
                this.#showConvertToast(data, 'AVIF');
            }
            return data;
        } catch (error) {
            if (options.notifyError !== false) {
                ImgEt.Utils.showNotification(`转换失败: ${error.message}`, 'error');
            }
            throw error;
        } finally {
            GalleryManager.setButtonLoadingState(btn, false, 'avif');
            this.endProcessing();
        }
    }

    static async processSingleConvert(filename, imgCard, target, options = {}) {
        target = ['jpg', 'png', 'webp', 'avif'].includes(String(target).toLowerCase()) ? String(target).toLowerCase() : 'webp';
        if (target === 'webp') return this.processSingleWebP(filename, imgCard, options);
        if (target === 'avif') return this.processSingleAvif(filename, imgCard, options);
        if (this.isProcessing()) {
            ImgEt.Utils.showNotification('有操作正在进行中', 'warning');
            return;
        }
        this.startProcessing();
        const btn = imgCard?.querySelector?.(`.convert-btn[data-convert-target="${target}"]`) || null;
        const label = target === 'png' ? 'PNG' : 'JPG';
        GalleryManager.setButtonLoadingState(btn, true, target);

        try {
            const data = await ApiService.request('/api/v1/action', { action: target, file: filename, csrf_token: window.CSRF_TOKEN || '' }, { method: 'POST' });
            if (options.dialog !== false) {
                this.#showConvertResult(data, label);
            }
            await this.#addConvertedCard(data, imgCard);
            if (options.notify !== false) {
                this.#showConvertToast(data, label);
            }
            return data;
        } catch (error) {
            if (options.notifyError !== false) {
                ImgEt.Utils.showNotification(`转换失败: ${error.message}`, 'error');
            }
            throw error;
        } finally {
            GalleryManager.setButtonLoadingState(btn, false, target);
            this.endProcessing();
        }
    }

    static #showCompressToast(data) {
        const title = '压缩完成';
        ImgEt.Utils.showNotification(
            title,
            'success',
            {
                variant: ['process', 'compress'],
                title,
                icon: 'fa-arrows-minimize',
                duration: 3000
            }
        );
    }

    static #showConvertToast(data, format) {
        const title = `${format} 转换完成`;
        ImgEt.Utils.showNotification(
            title,
            'success',
            {
                variant: ['process', 'convert'],
                title,
                icon: 'fa-wand-magic-sparkles',
                duration: 3000
            }
        );
    }

    static #showCompressResult(data, imgCard) {
        const originalSize = escapeHtml(data.original_size || data.before_size_text || '0 B');
        const compressedSize = escapeHtml(data.compressed_size || data.after_size_text || data.size_text || '0 B');
        const savedSize = escapeHtml(data.saved_size_text || data.saved_size || '0 B');
        const savedPercent = Number.isFinite(Number(data.saved_percent))
            ? Number(data.saved_percent).toFixed(1).replace(/\.0$/, '')
            : '0';
        const filename = imgCard?.dataset?.filename || imgCard?.querySelector?.('img')?.alt || '';
        const previewRaw = imgCard?.dataset?.thumbUrl
            || imgCard?.querySelector?.('img')?.getAttribute('src')
            || imgCard?.dataset?.url
            || '';
        const separator = previewRaw.includes('?') ? '&' : '?';
        const previewUrl = previewRaw ? `${previewRaw}${separator}t=${Date.now()}` : '';
        const engineLabels = {
            gd: 'GD',
            tinypng: 'TinyPNG',
            imagemagick: 'ImageMagick',
        };
        const method = String(data.method || data.mode || '').toLowerCase();
        const engineName = engineLabels[method] || method || '';
        const content = `
            <div class="convert-result compress-result-panel">
                ${previewUrl ? `
                    <div class="convert-result-preview">
                        <img src="${escapeHtml(previewUrl)}" alt="${escapeHtml(filename)}" loading="lazy">
                        <span class="convert-result-format">${escapeHtml(engineName || 'COMPRESS')}</span>
                    </div>
                ` : ''}
                <div class="convert-result-stats" aria-label="压缩结果">
                    <div class="convert-stat">
                        <span>压缩前</span>
                        <strong>${originalSize}</strong>
                    </div>
                    <div class="convert-stat is-current">
                        <span>压缩后</span>
                        <strong>${compressedSize}</strong>
                    </div>
                    <div class="convert-stat is-saved">
                        <span>节省</span>
                        <strong class="convert-saved-value">
                            <span>${savedSize}</span>
                            <em>${escapeHtml(savedPercent)}%</em>
                        </strong>
                    </div>
                </div>
            </div>`;
        ImgEt.DialogManager.showCustomDialog('压缩完成', content);
        const dialogs = Array.from(document.querySelectorAll('.custom-dialog'));
        const dialog = dialogs[dialogs.length - 1];
        dialog?.querySelector('.custom-dialog-content')?.classList.add('process-result-dialog-content', 'compress-dialog-content');
    }

    static #showConvertResult(data, format) {
        const safeFilename = escapeHtml(data.filename);
        const safeUrl = escapeHtml(data.url);
        const safePreviewUrl = escapeHtml(data.thumbnail_url || data.url);
        const beforeSize = escapeHtml(data.before_size_text || data.original_size || '0 B');
        const afterSize = escapeHtml(data.after_size_text || data.size_text || '0 B');
        const savedSize = escapeHtml(data.saved_size_text || data.saved_size || '0 B');
        const savedPercent = Number.isFinite(Number(data.saved_percent))
            ? Number(data.saved_percent).toFixed(1).replace(/\.0$/, '')
            : '0';
        const content = `
            <div class="convert-result">
                <div class="convert-result-preview">
                    <img src="${safePreviewUrl}" alt="${safeFilename}" loading="lazy">
                    <span class="convert-result-format">${escapeHtml(format)}</span>
                </div>
                <div class="convert-result-stats" aria-label="转换结果">
                    <div class="convert-stat">
                        <span>转换前</span>
                        <strong>${beforeSize}</strong>
                    </div>
                    <div class="convert-stat is-current">
                        <span>转换后</span>
                        <strong>${afterSize}</strong>
                    </div>
                    <div class="convert-stat is-saved">
                        <span>节省</span>
                        <strong class="convert-saved-value">
                            <span>${savedSize}</span>
                            <em>${escapeHtml(savedPercent)}%</em>
                        </strong>
                    </div>
                </div>
                <div class="convert-result-file">
                    <div class="convert-file-label">
                        <i class="fa-light fa-link" aria-hidden="true"></i>
                        <span>新文件 URL</span>
                    </div>
                    <div class="convert-file-copy">
                        <input type="text" value="${safeUrl}" readonly aria-label="转换后的文件 URL">
                        <button type="button" class="convert-copy-btn" data-copy="${safeUrl}" title="复制新文件 URL" aria-label="复制新文件 URL">
                            <i class="fa-light fa-copy" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>`;
        ImgEt.DialogManager.showCustomDialog(`${format} 转换完成`, content);
        const dialogs = Array.from(document.querySelectorAll('.custom-dialog'));
        const dialog = dialogs[dialogs.length - 1];
        dialog?.querySelector('.custom-dialog-content')?.classList.add('process-result-dialog-content', 'convert-dialog-content');
        dialog?.querySelector('.convert-copy-btn')?.addEventListener('click', (event) => {
            const button = event.currentTarget;
            if (button instanceof HTMLElement && button.dataset.copy) {
                ImgEt.Utils.copyToClipboard(button.dataset.copy, button);
            }
        });
    }

    static async #addConvertedCard(data, imgCard) {
        try {
            const newCardHtml = await ApiService.getCardTemplate(data);
            // 在原卡片后插入新生成的卡片
            imgCard.insertAdjacentHTML('afterend', newCardHtml);
            const newCard = imgCard.nextElementSibling;
            const gallery = document.querySelector('.gallery');
            if (newCard) {
                GalleryManager.initNewCard(newCard);
            }

            // 保证当前页卡片数量不超过每页限制（移除末尾卡片以维持数量）
            try {
                const perPage = GalleryManager.getPerPage();
                if (gallery) {
                    const cards = Array.from(gallery.querySelectorAll(':scope > .img-card, :scope > .img-box'));
                    if (cards.length > perPage) {
                        // 移除最后一个元素（通常是最后一张卡片），保持页面卡片数不变
                        const last = gallery.lastElementChild;
                        if (last && last !== newCard) last.remove();
                        // 如果最后一个正好是 newCard，则移除倒数第二个
                        else if (last && last === newCard) {
                            const prev = gallery.children[gallery.children.length - 2];
                            if (prev) prev.remove();
                        }
                    }
                }
            } catch (e) {
                console.warn('保持每页数量失败:', e);
            }

            // 更新计数显示
            GalleryManager.updateImageCount();
        } catch (err) {
            console.error('添加转换卡片失败:', err);
        }
    }
}

/**
 * BatchProcessor - 批量处理
 */
class BatchProcessor extends BaseProcessor {
    static #getActionStyle(action) {
        if (action === 'compress') {
            return { text: '压缩', variant: 'compress', icon: 'fa-arrows-minimize' };
        }
        if (action === 'webp' || action === 'avif' || action === 'jpg' || action === 'png') {
            return { text: '转换', variant: 'convert', icon: 'fa-wand-magic-sparkles' };
        }
        return { text: '删除', variant: 'delete', icon: 'fa-trash' };
    }

    /**
     * 进度反馈拆成两半:
     *   - 顶部 toast (showNotification) → 状态广播,操作开始 / 完成 / 失败
     *   - 底部进度卡 (ImgEt.BatchProgress) → 实时计数 + 进度条 + 百分比
     *
     * 这两个方法以前是私有 #upsertProcessProgressToast / #upsertDeleteProgressToast
     * 直接往 #notification 容器塞自定义 HTML,导致顶部 toast 既要做状态又要
     * 显示进度,视觉上过载。重构后只调一次 BatchProgress.show 就够了。
     */

    static #resetSelection() {
        const selectAllCheckbox = document.querySelector('#selectAll');
        if (selectAllCheckbox) selectAllCheckbox.checked = false;
        document.querySelectorAll('.select-img:checked').forEach(cb => cb.checked = false);
        GalleryManager.updateSelectedCount();
        GalleryManager.updateImageCount();
    }

    static async #queueBatchAvif(files) {
        const data = await ApiService.request('/api/v1/action', {
            action: 'queue_avif',
            files: JSON.stringify(files),
            csrf_token: window.CSRF_TOKEN || ''
        }, { method: 'POST' });

        const queued = Number(data?.queued || 0);
        const skipped = Number(data?.skipped || 0);
        const failed = Number(data?.failed || 0);
        const pending = Number(data?.task_status?.pending || 0);
        const details = [`已加入 AVIF 异步任务 ${queued} 张`];
        if (skipped > 0) details.push(`跳过 ${skipped} 张`);
        if (failed > 0) details.push(`失败 ${failed} 张`);
        if (pending > 0) details.push(`队列待处理 ${pending} 个`);

        ImgEt.Utils.showNotification(
            '批量转换已入队',
            failed > 0 ? 'warning' : 'success',
            {
                variant: ['process', 'batch', 'convert'],
                title: '批量转换已入队',
                detail: details.join('，'),
                meta: '请到设置页处理导入任务',
                icon: 'fa-list-check',
                duration: 5200
            }
        );
    }

    static async start(action) {
        if (this.isProcessing()) {
            ImgEt.Utils.showNotification('有操作正在进行中', 'warning');
            return;
        }

        const selectedFiles = Array.from(document.querySelectorAll('.select-img:checked')).map(cb => cb.value);
        if (selectedFiles.length === 0) {
            ImgEt.Utils.showNotification('请选择要处理的图片', 'warning');
            return;
        }

        const actionStyle = this.#getActionStyle(action);
        const actionText = actionStyle.text;
        const message = action === 'delete'
            ? `删除后，选中的 ${selectedFiles.length} 张图片将不可恢复，相关图片链接也将失效。确认删除吗？`
            : action === 'avif' && selectedFiles.length > 1
            ? `确定要将选中的 ${selectedFiles.length} 张图片加入 AVIF 异步任务队列吗？`
            : `确定要批量${actionText}选中的 ${selectedFiles.length} 张图片吗？`;
        const title = action === 'delete' ? '批量删除图片' : `批量${actionText}确认`;
        // 转换会新建目标格式文件、原图保留 —— 给「保留原图」开关(默认保留),
        // 关掉则转换成功后自动删除原图。AVIF 多张走异步队列、compress 为原地
        // 覆盖,都没有可删的独立原图,故不显示该开关。
        const isSyncConvert = ['webp', 'jpg', 'png'].includes(action)
            || (action === 'avif' && selectedFiles.length <= 1);
        const confirmOptions = action === 'delete'
            ? { danger: true, confirmText: '删除' }
            : (isSyncConvert ? { toggle: { label: '保留原图(关闭则转换后自动删除原图)', checked: true } } : {});

        ImgEt.DialogManager.showConfirmDialog(title, message, (keepOriginal) => {
            const deleteOriginal = isSyncConvert && keepOriginal === false;
            this.process(action, selectedFiles, deleteOriginal);
        }, confirmOptions);
    }

    // 转换成功后删除原图。删除失败不应让整批判失败 —— 转换已成功,仅告警。
    static async #deleteOriginalAfterConvert(filename, imgCard) {
        try {
            await DeleteManager.processSingle(filename, true, { notifySuccess: false, notifyError: false });
        } catch (e) {
            console.warn('转换后删除原图失败:', filename, e);
        }
    }

    static async process(action, files, deleteOriginal = false) {
        if (action === 'avif' && files.length > 1) {
            this.startProcessing();
            try {
                await this.#queueBatchAvif(files);
                this.#resetSelection();
            } catch (error) {
                ImgEt.Utils.showNotification(`AVIF 任务入队失败: ${error.message}`, 'error');
            } finally {
                this.endProcessing();
            }
            return;
        }

        this.startProcessing();
        const results = { success: 0, fail: 0 };
        const total = files.length;
        const actionStyle = this.#getActionStyle(action);
        const actionText = actionStyle.text;
        const progressLabel = `批量${actionText}`;

        // 顶部状态 toast — 只发一次,告诉用户「开始了」。短停留(2.5s)即自然消失,
        // 不跟底部进度卡视觉打架。完成 toast 由循环结束后那段处理(更详细)。
        ImgEt.Utils.showNotification(`${progressLabel}中...`, 'info', { duration: 2500 });
        // 底部进度卡 — 立刻显示 0/total,后面循环里逐张更新
        ImgEt.BatchProgress.show(actionStyle.variant, progressLabel, 0, total);

        for (const [index, filename] of files.entries()) {
            const imgCard = document.querySelector(getImageCardSelector(filename));
            if (!imgCard) {
                results.fail++;
                continue;
            }

            ImgEt.BatchProgress.show(actionStyle.variant, progressLabel, index + 1, total);

            try {
                switch (action) {
                    case 'compress':
                        await ImageProcessor.processSingleCompress(filename, imgCard, { notify: false, notifyError: false, dialog: false });
                        break;
                    case 'webp':
                        await ImageProcessor.processSingleWebP(filename, imgCard, { notify: false, notifyError: false, dialog: false });
                        if (deleteOriginal) await this.#deleteOriginalAfterConvert(filename, imgCard);
                        break;
                    case 'avif':
                        await ImageProcessor.processSingleAvif(filename, imgCard, { notify: false, notifyError: false, dialog: false });
                        if (deleteOriginal) await this.#deleteOriginalAfterConvert(filename, imgCard);
                        break;
                    case 'jpg':
                    case 'png':
                        await ImageProcessor.processSingleConvert(filename, imgCard, action, { notify: false, notifyError: false, dialog: false });
                        if (deleteOriginal) await this.#deleteOriginalAfterConvert(filename, imgCard);
                        break;
                    case 'delete':
                        await DeleteManager.processSingle(filename, true, {
                            notifySuccess: false,
                            notifyError: false
                        }); // 批量删除时抑制单次补位和 toast
                        break;
                    default:
                        throw new Error('未知操作');
                }
                results.success++;
            } catch (error) {
                results.fail++;
            }
        }

        // 收尾 — 失败时直接收掉进度卡(避免误导),全成功走 done() 演出 100% 完成态
        if (results.fail > 0 && results.success === 0) {
            ImgEt.BatchProgress.hide();
        } else {
            ImgEt.BatchProgress.done();
        }

        if (action === 'delete') {
            ImgEt.Utils.showNotification(
                `批量${actionText}完成: ${results.success}张成功${results.fail ? `, ${results.fail}张失败` : ''}`,
                results.fail ? 'warning' : 'success'
            );
        } else {
            ImgEt.Utils.showNotification(
                `批量${actionText}完成`,
                results.fail ? 'warning' : 'success',
                {
                    variant: ['process', 'batch', actionStyle.variant],
                    title: `批量${actionText}完成`,
                    detail: `${results.success}/${total} 张成功`,
                    meta: results.fail ? `${results.fail} 张失败` : '全部处理完成',
                    icon: results.fail ? 'fa-triangle-exclamation' : actionStyle.icon,
                    duration: 5200
                }
            );
        }

        // 重置选择状态
        this.#resetSelection();

        // 批量删除后重拉当前页，确保后续页图片补位和分页导航同步
        if (action === 'delete' && results.success > 0) {
            try {
                await GalleryManager.refreshCurrentPage();
            } catch (error) {
                console.warn('刷新图库失败:', error);
                ImgEt.Utils.showNotification('图片已删除，刷新图库失败，请手动刷新页面', 'warning');
            }
        }

        this.endProcessing();
    }
}

/**
 * GalleryManager - UI 及交互管理
 */
class GalleryManager {
    static elements = {};

    static init() {
        if (document.querySelector('.gallery')) {
            this.initGalleryPage();
        } else if (document.querySelector('.upload-grid')) {
            this.initUploadPage();
        }
    }

    static initGalleryPage() {
        this.initDOMElements();
        this.bindEvents();
        this.initFilters();
        this.initLazyLoad();
        this.updateImageCount();
    }

    static initUploadPage() {
        const container = document.querySelector('.upload-grid');
        if (!container || container.dataset.eventsBound === '1') return;
        container.dataset.eventsBound = '1';
        container.addEventListener('click', e => {
            const btn = e.target.closest('.action-btn');
            if (!btn || btn.classList.contains('copy-btn')) return;
            const card = btn.closest('.img-box');
            const filename = card?.dataset.filename;
            if (!filename) return;
            this.handleImageAction(btn, card, filename);
        });
    }

    static async refreshRecentUploads(limit = 5) {
        const uploadGrid = document.querySelector('.upload-grid');
        if (!uploadGrid) return false;

        const response = await ApiService.request('/api/v1/action', {
            action: 'get_next_image',
            current_count: 0,
            count: limit
        });

        if (response?.status !== 'success') {
            throw new Error(response?.message || '最近上传刷新失败');
        }

        const images = Array.isArray(response.images) ? response.images.slice(0, limit) : [];
        this.updateRecentUploadTime(images);
        if (images.length === 0) {
            uploadGrid.innerHTML = `
                <div class="recent-empty">
                    <i class="fa-light fa-image"></i>
                    <span>暂无最近上传图片</span>
                </div>
            `;
            return true;
        }

        const fragments = [];
        for (const image of images) {
            try {
                fragments.push(await ApiService.getCardTemplate(image, 'recent'));
            } catch (error) {
                console.error('刷新最近上传卡片失败:', error);
            }
        }

        uploadGrid.innerHTML = fragments.join('');
        uploadGrid.querySelectorAll('.img-box').forEach(card => {
            GalleryManager.initNewCard(card);
            card.classList.add('appearing');
            requestAnimationFrame(() => {
                requestAnimationFrame(() => card.classList.remove('appearing'));
            });
        });
        return true;
    }

    static updateRecentUploadTime(images = []) {
        const tag = document.querySelector('[data-recent-time]');
        if (!tag) return;

        if (!Array.isArray(images) || images.length === 0) {
            tag.textContent = '暂无上传';
            return;
        }

        const lastImage = images[images.length - 1] || {};
        const timestamp = Number(lastImage.time || lastImage.created_at || 0);
        const date = GalleryManager.formatRecentUploadTime(timestamp);
        tag.textContent = date ? `最后一张 ${date}` : '最后一张 时间未知';
    }

    static formatRecentUploadTime(timestamp) {
        if (!Number.isFinite(timestamp) || timestamp <= 0) return '';
        const milliseconds = timestamp > 1000000000000 ? timestamp : timestamp * 1000;
        const date = new Date(milliseconds);
        if (Number.isNaN(date.getTime())) return '';

        const pad = value => String(value).padStart(2, '0');
        const day = [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate())
        ].join('-');
        const time = [pad(date.getHours()), pad(date.getMinutes())].join(':');
        return `${day} ${time}`;
    }

    static initDOMElements() {
        this.elements = {
            gallery: document.querySelector('.gallery'),
            filterType: document.querySelector('.filter-type'),
            filterSort: document.querySelector('.filter-sort'),
            searchInput: document.querySelector('.search-input'),
            selectAll: document.querySelector('#selectAll'),
            batchBtns: document.querySelectorAll('.batch-btn'),
            totalCount: document.querySelector('.total-count'),
            selectedCount: document.querySelector('.selected-count')
        };
    }

    // 获取当前页面每页显示数量（读取页面 select#perPage），回退到默认 18
    static getPerPage() {
        const sel = document.querySelector('#perPage');
        const val = sel ? parseInt(sel.value, 10) : NaN;
        return Number.isInteger(val) && val > 0 ? val : 18;
    }

    static bindEvents() {
        if (this.elements.gallery?.dataset.eventsBound === '1') return;
        if (this.elements.gallery) this.elements.gallery.dataset.eventsBound = '1';

        // 缩略图点击：使用 ViewImage 打开原图
        this.elements.gallery?.addEventListener('click', e => {
            const previewImg = e.target.closest('.img-preview img');
            if (!previewImg) return;
            // 点击操作按钮/复选框时不触发预览
            if (e.target.closest('.action-btn, .img-actions, .img-select')) return;

            const card = previewImg.closest('.img-card');
            const originalUrl = card?.dataset.url || previewImg.getAttribute('src') || '';
            if (!originalUrl) return;

            const allUrls = Array.from(document.querySelectorAll('.gallery .img-card'))
                .filter(item => item instanceof HTMLElement && item.style.display !== 'none')
                .map(item => item.dataset.url || item.querySelector('.img-preview img')?.getAttribute('src') || '')
                .filter(Boolean);

            if (window.ViewImage && typeof window.ViewImage.display === 'function') {
                window.ViewImage.display(allUrls.length ? allUrls : [originalUrl], originalUrl);
            } else {
                window.open(originalUrl, '_blank', 'noopener');
            }
        });

        this.elements.gallery?.addEventListener('click', e => {
            const btn = e.target.closest('.action-btn');
            if (!btn) return;
            const imgCard = btn.closest('.img-card');
            const filename = imgCard?.dataset.filename;
            if (filename) this.handleImageAction(btn, imgCard, filename);
        });

        // 缩略图右键：保留系统右键菜单，但把“查看图片”目标切换为原图地址
        this.elements.gallery?.addEventListener('contextmenu', e => {
            const previewImg = e.target.closest('.img-preview img');
            if (!previewImg) return;

            const card = previewImg.closest('.img-card');
            const originalUrl = card?.dataset.url || '';
            if (!originalUrl) return;

            const currentSrc = previewImg.getAttribute('src') || '';
            if (currentSrc === originalUrl) {
                return;
            }

            previewImg.setAttribute('data-thumb-src', currentSrc);
            previewImg.setAttribute('src', originalUrl);

            // 右键菜单后恢复缩略图显示（不影响“查看图片”打开原图）
            window.setTimeout(() => {
                const thumbSrc = previewImg.getAttribute('data-thumb-src') || '';
                if (thumbSrc !== '') {
                    previewImg.setAttribute('src', thumbSrc);
                    previewImg.removeAttribute('data-thumb-src');
                }
            }, 1500);
        });

        this.elements.batchBtns?.forEach(btn => btn.addEventListener('click', () => BatchProcessor.start(btn.dataset.action)));

        const filterHandler = ImgEt.Utils.debounce(() => this.filterGallery(), 300);
        this.elements.filterType?.addEventListener('change', filterHandler);
        this.elements.filterSort?.addEventListener('change', filterHandler);
        this.elements.searchInput?.addEventListener('input', filterHandler);

        this.elements.selectAll?.addEventListener('change', e => {
            document.querySelectorAll('.img-card:not([style*="display: none"]) .select-img').forEach(cb => cb.checked = e.target.checked);
            this.updateSelectedCount();
        });

        this.elements.gallery?.addEventListener('change', e => {
            if (e.target.classList.contains('select-img')) this.updateSelectedCount();
        });
    }

    static async refreshCurrentPage() {
        const shell = document.querySelector('.gallery-shell');
        if (!shell) return false;

        const preserved = {
            type: this.elements.filterType?.value || 'all',
            sort: this.elements.filterSort?.value || 'date-desc',
            search: this.elements.searchInput?.value || ''
        };

        const response = await fetch(window.location.href, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            },
            cache: 'no-store',
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const nextShell = doc.querySelector('.gallery-shell');
        if (!nextShell) {
            throw new Error('未找到图库内容');
        }

        shell.replaceWith(nextShell);
        this.initGalleryPage();

        if (this.elements.filterType) this.elements.filterType.value = preserved.type;
        if (this.elements.filterSort) this.elements.filterSort.value = preserved.sort;
        if (this.elements.searchInput) this.elements.searchInput.value = preserved.search;
        this.filterGallery();
        this.updateSelectedCount();

        return true;
    }

    static handleImageAction(btn, card, filename) {
        if (btn.classList.contains('copy-btn')) {
            const imgUrl = card.dataset.url || card.querySelector('img')?.src;
            this.showCopyDialog(filename, imgUrl);
        } else if (btn.classList.contains('compress-btn')) {
            ImageProcessor.processSingleCompress(filename, card);
        } else if (btn.classList.contains('webp-btn')) {
            ImageProcessor.processSingleWebP(filename, card);
        } else if (btn.classList.contains('avif-btn')) {
            ImageProcessor.processSingleAvif(filename, card);
        } else if (btn.classList.contains('convert-btn')) {
            ImageProcessor.processSingleConvert(filename, card, btn.dataset.convertTarget || 'webp');
        } else if (btn.classList.contains('delete-btn')) {
            DeleteManager.handleDelete(filename);
        }
    }

    /**
     * 把单张图扔回处理队列（priority=10 优先于普通上传任务）。
     * 后台 worker 会按当前 settings 重新生成缩略图 / WebP / AVIF / 水印。
     * 适用场景：用户改了压缩设置 / 想给老图补一个 AVIF 版本 / 之前处理失败想再来一次。
     */
    static async handleReprocess(filename, card, btn) {
        if (!filename) return;
        const confirmed = await ImgEt.DialogManager.confirm(
            '重新处理图片',
            `将 ${filename} 扔回处理队列？后台会按当前设置重新生成缩略图、格式转换、水印，不会动原图。`
        );
        if (!confirmed) {
            return;
        }
        const originalHTML = btn?.innerHTML;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-light fa-spinner fa-spin"></i>';
        }
        try {
            const resp = await fetch('/api/v1/queue/reprocess?filename=' + encodeURIComponent(filename), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await resp.json().catch(() => ({}));
            if (data.status !== 'success') {
                throw new Error(data.message || '请求失败');
            }
            ImgEt.Utils.showNotification(
                `已加入队列（队列深度：${data.queue_pending}）— 处理完成后刷新即可看到新版本`,
                'success'
            );
        } catch (e) {
            ImgEt.Utils.showNotification('重新处理失败：' + (e.message || e), 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }
    }

    static showCopyDialog(filename, imgUrl) {
        const formats = [
            { type: 'URL', code: imgUrl },
            { type: 'HTML', code: `<img src="${imgUrl}" alt="${filename}">` },
            { type: 'Markdown', code: `![${filename}](${imgUrl})` },
            { type: 'BBCode', code: `[img]${imgUrl}[/img]` }
        ];
        const content = `
            <div class="copy-options">
                ${formats.map(({ type, code }) => {
                    const escapedCode = code.replace(/"/g, '&quot;');
                    const inputId = `copy-input-${type.toLowerCase()}`;
                    return `
                        <div class="copy-option">
                            <div class="copy-option-header">${type}</div>
                            <div class="copy-option-content">
                                <input type="text" id="${inputId}" name="${inputId}" value="${escapedCode}" readonly>
                                <button class="copy-btn" data-copy="${escapedCode}"><i class="fa-light fa-copy"></i></button>
                            </div>
                        </div>`;
                }).join('')}
            </div>`;
        ImgEt.DialogManager.showCustomDialog('复制图片链接', content);
        document.querySelector('.custom-dialog .copy-options')?.addEventListener('click', e => {
            const copyBtn = e.target.closest('.copy-btn');
            if (copyBtn && copyBtn.dataset.copy) ImgEt.Utils.copyToClipboard(copyBtn.dataset.copy, copyBtn);
        });
    }

    static filterGallery() {
        const type = this.elements.filterType?.value || 'all';
        const sort = this.elements.filterSort?.value || 'date-desc';
        const search = this.elements.searchInput?.value?.toLowerCase() || '';

        document.querySelectorAll('.img-card').forEach(card => {
            const filename = (card.dataset.filename || '').toLowerCase();
            const ext = filename.split('.').pop();
            const typeMatch = type === 'all' || type === ext;
            const searchMatch = !search || filename.includes(search);
            card.style.display = typeMatch && searchMatch ? '' : 'none';
        });

        this.sortGallery(sort);
    }

    static sortGallery(sort) {
        const gallery = this.elements.gallery;
        if (!gallery) return;
        const cards = Array.from(gallery.children);
        cards.sort((a, b) => {
            const key = sort.startsWith('date') ? 'date' : 'size';
            const aVal = parseInt(a.dataset[key] || '0', 10);
            const bVal = parseInt(b.dataset[key] || '0', 10);
            return sort.includes('desc') ? bVal - aVal : aVal - bVal;
        });
        cards.forEach(card => gallery.appendChild(card));
    }

    static initFilters() {
        if (this.elements.filterType) this.elements.filterType.value = 'all';
        if (this.elements.filterSort) this.elements.filterSort.value = 'date-desc';
        if (this.elements.searchInput) this.elements.searchInput.value = '';
        this.filterGallery();
    }

    static initLazyLoad() {
        if (!('IntersectionObserver' in window)) return;
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    obs.unobserve(img);
                }
            });
        });
        document.querySelectorAll('.img-card img[data-src]').forEach(img => observer.observe(img));
    }

    static updateBatchButtons() {
        const selectedCount = document.querySelectorAll('.select-img:checked').length;
        this.elements.batchBtns?.forEach(btn => btn.disabled = selectedCount === 0);
    }

    static updateSelectedCount() {
        const selectedCount = document.querySelectorAll('.select-img:checked').length;
        if (this.elements.selectedCount) this.elements.selectedCount.textContent = `已选择 ${selectedCount} 张图片`;
        this.updateBatchButtons();
    }

    static updateImageCount() {
        const totalCount = document.querySelector('.total-count');
        if (totalCount) {
            // 使用存储的总数，而不是当前可见的图片数
            const total = totalCount.dataset.total;
            totalCount.textContent = `(共 ${Number(total).toLocaleString()} 张图片)`;
        }
    }

    static setButtonLoadingState(btn, isLoading, action = '') {
        if (!btn) return;
        btn.disabled = isLoading;
        btn.classList.toggle('loading', isLoading);
        if (isLoading) {
            btn.innerHTML = '<i class="fa-light fa-spinner-third fa-spin"></i>';
        } else {
            const iconClass = { compress: 'fa-compress', webp: 'fa-image', avif: 'fa-image', jpg: 'fa-image', png: 'fa-image', delete: 'fa-trash' }[action] || 'fa-check';
            btn.innerHTML = `<i class="fa-light ${iconClass}"></i>`;
        }
    }

    static initNewCard(card) {
        if (!card) return;
        const img = card.querySelector('img[data-src]');
        if (img && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                        }
                        obs.unobserve(img);
                    }
                });
            });
            observer.observe(img);
        }
    }
}

// 初始化
document.addEventListener('DOMContentLoaded', () => {
    try {
        if (!window.ImgEt?.Utils || !window.ImgEt.DialogManager) throw new Error('核心模块未加载');
        GalleryManager.init();
    } catch (error) {
        console.error('图库初始化失败:', error);
        window.ImgEt?.Utils?.showNotification?.('初始化失败，请刷新页面重试', 'error');
    }
});

document.addEventListener('pjax:loaded', () => {
    try {
        GalleryManager.init();
    } catch (error) {
        console.error('PJAX 图库初始化失败:', error);
        window.ImgEt?.Utils?.showNotification?.('图库初始化失败，请刷新页面重试', 'error');
    }
});


/* ===== upload.js ===== */
/**
 * 上传管理器类
 */
class UploadManager {
    // 配置常量
    static CONFIG = {
        MAX_SIZE: 20 * 1024 * 1024, // 默认最大文件大小（会被后端传值覆盖）
        TIMEOUT_SMALL: 60000, // <= 2MB: 60秒
        TIMEOUT_MEDIUM: 180000, // <= 10MB: 180秒
        TIMEOUT_LARGE: 300000, // > 10MB: 300秒
        TIMEOUT_SMALL_THRESHOLD: 2 * 1024 * 1024,
        TIMEOUT_MEDIUM_THRESHOLD: 10 * 1024 * 1024,
        MAX_FILES: 100,
        MAX_CONCURRENT: 3, // 默认 3 并发；后台可调高，避免 PHP/SQLite 被批量上传打满
        DUPLICATE_HASH_MAX_FILES: 20,
        DUPLICATE_HASH_MAX_TOTAL_BYTES: 200 * 1024 * 1024,
        FADE_DURATION: 300, // 动画过渡时间
    };

    constructor() {
        this.initElements();
        this.batchTotal = 0;
        this.batchCompleted = 0;
        this.batchSucceeded = 0;
        this.batchSkipped = 0;
        this.batchFailed = 0;
        this.batchNotified = false;
        this.activeUploads = 0;
        this.uploadQueue = [];
        this.uploadRecords = new Map();
        this.uploadSequence = 0;
        this.activeRunIds = new Set();
        this.batchRunId = 0;
        this.progressItem = null;
        this.batchProgressVisible = false;
        this.queueToastItem = null;
        this.batchUploadToastItem = null;
        this.batchUploadToastTimer = 0;
        this.completedUploads = [];
        this.headerUploadButton = document.querySelector('.nav-cta-btn[href="/upload"]');
        this.headerUploadIcon = this.headerUploadButton?.querySelector('i') || null;
        this.headerUploadLoader = this.headerUploadButton?.querySelector('.nav-upload-loader') || null;
        this.headerUploadLabel = this.headerUploadButton?.querySelector('span') || null;
        this.headerUploadDefaultIcon = this.headerUploadIcon ? this.headerUploadIcon.className : '';
        this.headerUploadDefaultText = this.headerUploadLabel ? this.headerUploadLabel.textContent : '上传';
        this.lastNavigationNoticeAt = 0;
        this.maxSize = UploadManager.CONFIG.MAX_SIZE;
        this.maxFiles = UploadManager.CONFIG.MAX_FILES;
        this.maxConcurrent = UploadManager.CONFIG.MAX_CONCURRENT;
        this.autoCompressEnabled = false;
        this.autoConvertEnabled = false;
        this.autoConvertFormat = 'webp';
        this.autoWebpEnabled = false;
        this.autoAvifEnabled = false;
        this.allowedExtensions = new Set();
        if (this.elements.imageInput && this.elements.imageInput.dataset.maxSize) {
            const parsed = parseInt(this.elements.imageInput.dataset.maxSize, 10);
            if (Number.isFinite(parsed) && parsed > 0) {
                this.maxSize = parsed;
            }
        }
        if (this.elements.imageInput && this.elements.imageInput.dataset.maxFiles) {
            const parsed = parseInt(this.elements.imageInput.dataset.maxFiles, 10);
            if (Number.isFinite(parsed) && parsed > 0) {
                this.maxFiles = parsed;
            }
        }
        if (this.elements.imageInput && this.elements.imageInput.dataset.maxConcurrent) {
            const parsed = parseInt(this.elements.imageInput.dataset.maxConcurrent, 10);
            if (Number.isFinite(parsed) && parsed > 0) {
                this.maxConcurrent = Math.max(1, Math.min(20, parsed));
            }
        }
        if (this.elements.imageInput) {
            this.autoCompressEnabled = this.elements.imageInput.dataset.autoCompress === '1';
            this.autoWebpEnabled = this.elements.imageInput.dataset.autoWebp === '1';
            this.autoAvifEnabled = this.elements.imageInput.dataset.autoAvif === '1';
            this.autoConvertEnabled = this.elements.imageInput.dataset.autoConvert === '1' || this.autoWebpEnabled || this.autoAvifEnabled;
            this.autoConvertFormat = String(this.elements.imageInput.dataset.convertFormat || this.elements.processingControls?.dataset.convertFormat || (this.autoAvifEnabled ? 'avif' : 'webp')).toLowerCase();
            const allowedTypes = String(this.elements.imageInput.dataset.allowedTypes || '')
                .split(',')
                .map(type => type.trim().replace(/^\./, '').toLowerCase())
                .filter(Boolean);
            this.allowedExtensions = new Set(allowedTypes);
        }
        if (!this.elements.dropZone) return;
        this.bindEvents();
        this.bindUploadProcessingSettings();
        this.renderUploadQueue();
    }

    /**
     * 初始化DOM元素
     */
    initElements() {
        this.elements = {
            dropZone: document.getElementById('dropZone'),
            uploadForm: document.getElementById('uploadForm'),
            imageInput: document.getElementById('imageInput'),
            processingControls: document.querySelector('[data-upload-processing-controls]'),
            queuePanel: document.getElementById('uploadQueuePanel'),
            queueList: document.getElementById('uploadQueueList'),
            queueCount: document.getElementById('uploadQueueCount'),
            clearQueueBtn: document.getElementById('clearUploadQueue'),
            uploadAllBtn: document.getElementById('uploadAllQueued'),
            progressBar: document.querySelector('.progress-bar-inner'),
            progressText: document.querySelector('.progress-text'),
            uploadProgress: document.getElementById('uploadProgress')
        };
    }

    /**
     * 绑定事件处理
     */
    bindEvents() {
        // 拖拽上传
        this.elements.dropZone.addEventListener('dragover', this.handleDragOver.bind(this));
        this.elements.dropZone.addEventListener('dragleave', this.handleDragLeave.bind(this));
        this.elements.dropZone.addEventListener('drop', this.handleDrop.bind(this));

        // 文件选择上传
        this.elements.imageInput.addEventListener('change', (e) => {
            this.handleFiles(e.target.files);
        });

        // 粘贴上传
        document.addEventListener('paste', this.handlePaste.bind(this));
        document.addEventListener('click', this.handleNavigationWhileUploading.bind(this), true);

        this.elements.uploadAllBtn?.addEventListener('click', () => this.uploadAllQueued());
        this.elements.clearQueueBtn?.addEventListener('click', () => this.clearUploadQueue());
        this.elements.queueList?.addEventListener('click', this.handleQueueAction.bind(this));
    }

    bindUploadProcessingSettings() {
        const controls = this.elements.processingControls;
        if (!controls) return;

        const toggles = Array.from(controls.querySelectorAll('[data-upload-setting-toggle]'));
        if (toggles.length === 0) return;

        toggles.forEach(toggle => {
            toggle.addEventListener('change', () => {
                const changed = toggle.dataset.uploadSettingToggle || '';
                this.saveUploadProcessingSettings(changed, toggle);
            });
        });
    }

    getUploadProcessingState() {
        const controls = this.elements.processingControls;
        const compressToggle = controls?.querySelector('[data-upload-setting-toggle="compress"]');
        const convertToggle = controls?.querySelector('[data-upload-setting-toggle="convert"]');
        const watermarkToggle = controls?.querySelector('[data-upload-setting-toggle="watermark"]');
        return {
            compress: !!compressToggle?.checked,
            convert: !!convertToggle?.checked,
            watermark: !!watermarkToggle?.checked,
            format: String(controls?.dataset.convertFormat || 'webp').toLowerCase()
        };
    }

    async saveUploadProcessingSettings(changed, activeToggle = null) {
        const controls = this.elements.processingControls;
        if (!controls) return;

        const before = {
            compress: this.autoCompressEnabled,
            convert: this.autoConvertEnabled,
            webp: this.autoWebpEnabled,
            avif: this.autoAvifEnabled,
            format: String(controls.dataset.convertFormat || 'webp').toLowerCase()
        };
        const state = this.getUploadProcessingState();
        const csrfToken = controls.dataset.csrfToken || '';
        const toggleShell = activeToggle?.closest('.upload-setting-toggle') || null;

        if (toggleShell) {
            toggleShell.classList.add('is-saving');
        }

        try {
            const formData = new FormData();
            formData.append('form_action', 'save_upload_processing');
            formData.append('ajax', '1');
            formData.append('csrf_token', csrfToken);
            formData.append('changed', changed || '');
            formData.append('auto_compress_on_upload', state.compress ? '1' : '0');
            formData.append('auto_convert_on_upload', state.convert ? '1' : '0');
            formData.append('watermark_enabled', state.watermark ? '1' : '0');
            formData.append('convert_preferred_format', ['webp', 'avif', 'jpg', 'png'].includes(state.format) ? state.format : 'webp');

            const response = await fetch('/upload', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || !data?.success) {
                throw new Error(data?.message || '保存上传处理设置失败');
            }

            this.applyUploadProcessingSettings(data.settings || {});
            if (window.ImgEt?.Utils?.showNotification) {
                ImgEt.Utils.showNotification(data.message || '上传处理设置已更新', 'success');
            }
        } catch (error) {
            this.applyUploadProcessingSettings({
                auto_compress_on_upload: before.compress,
                auto_convert_on_upload: before.convert,
                auto_convert_webp_on_upload: before.webp,
                auto_convert_avif_on_upload: before.avif,
                convert_preferred_format: before.format,
                compression_label: before.compress ? '已开启' : '关闭',
                conversion_label: before.convert ? before.format.toUpperCase() : '关闭'
            });
            if (window.ImgEt?.Utils?.showNotification) {
                ImgEt.Utils.showNotification(error.message || '保存上传处理设置失败', 'error');
            }
        } finally {
            if (toggleShell) {
                toggleShell.classList.remove('is-saving');
            }
        }
    }

    applyUploadProcessingSettings(settings) {
        const controls = this.elements.processingControls;
        const imageInput = this.elements.imageInput;
        if (!controls || !imageInput) return;

        const autoCompress = !!settings.auto_compress_on_upload;
        const autoWebp = !!settings.auto_convert_webp_on_upload;
        const autoAvif = !!settings.auto_convert_avif_on_upload;
        const autoConvert = autoWebp || autoAvif || !!settings.auto_convert_on_upload;
        const rawFormat = String(settings.convert_preferred_format || controls.dataset.convertFormat || 'webp').toLowerCase();
        const format = ['webp', 'avif', 'jpg', 'png'].includes(rawFormat) ? rawFormat : 'webp';

        this.autoCompressEnabled = autoCompress;
        this.autoConvertEnabled = autoConvert;
        this.autoConvertFormat = format;
        this.autoWebpEnabled = autoConvert && format === 'webp';
        this.autoAvifEnabled = autoConvert && format === 'avif';
        imageInput.dataset.autoCompress = this.autoCompressEnabled ? '1' : '0';
        imageInput.dataset.autoConvert = this.autoConvertEnabled ? '1' : '0';
        imageInput.dataset.convertFormat = format;
        imageInput.dataset.autoWebp = this.autoWebpEnabled ? '1' : '0';
        imageInput.dataset.autoAvif = this.autoAvifEnabled ? '1' : '0';
        controls.dataset.convertFormat = format;

        const compressToggle = controls.querySelector('[data-upload-setting-toggle="compress"]');
        const convertToggle = controls.querySelector('[data-upload-setting-toggle="convert"]');
        const watermarkToggle = controls.querySelector('[data-upload-setting-toggle="watermark"]');
        if (compressToggle) {
            compressToggle.checked = this.autoCompressEnabled;
            compressToggle.closest('.upload-setting-toggle')?.classList.toggle('is-active', this.autoCompressEnabled);
        }
        if (convertToggle) {
            convertToggle.checked = this.autoConvertEnabled;
            convertToggle.closest('.upload-setting-toggle')?.classList.toggle('is-active', this.autoConvertEnabled);
        }
        // 水印 toggle 反映从 settings.watermark_enabled 来的全局开关
        if (watermarkToggle && settings.watermark_enabled !== undefined) {
            const wm = !!settings.watermark_enabled;
            watermarkToggle.checked = wm;
            watermarkToggle.closest('.upload-setting-toggle')?.classList.toggle('is-active', wm);
            const wmValue = controls.querySelector('[data-upload-watermark-value]');
            if (wmValue) wmValue.textContent = wm ? (settings.watermark_label || '开启') : '关闭';
        }

        const compressValue = controls.querySelector('[data-upload-compress-value]');
        const convertValue = controls.querySelector('[data-upload-convert-value]');
        if (compressValue) {
            compressValue.textContent = this.autoCompressEnabled ? (settings.compression_label || '开启') : '关闭';
        }
        if (convertValue) {
            const formatLabel = { webp: 'WebP', avif: 'AVIF', jpg: 'JPG', png: 'PNG' }[format] || format.toUpperCase();
            convertValue.textContent = this.autoConvertEnabled
                ? (settings.conversion_label || formatLabel)
                : '关闭';
        }
    }

    /**
     * 处理拖拽事件
     */
    handleDragOver(e) {
        e.preventDefault();
        this.elements.dropZone.classList.add('dragover');
    }

    handleDragLeave() {
        this.elements.dropZone.classList.remove('dragover');
    }

    handleDrop(e) {
        e.preventDefault();
        this.elements.dropZone.classList.remove('dragover');
        this.handleFiles(e.dataTransfer.files);
    }

    /**
     * 处理粘贴事件
     */
    handlePaste(e) {
        const images = Array.from(e.clipboardData.items)
            .filter(item => item.type.startsWith('image/'))
            .map(item => item.getAsFile());

        if (images.length > 0) {
            this.handleFiles(images);
        }
    }

    isBatchUploading() {
        return this.batchTotal > 0 && this.batchCompleted < this.batchTotal;
    }

    handleNavigationWhileUploading(event) {
        if (!this.isBatchUploading()) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('a[href]');
        if (!link) return;
        if ((link.target && link.target !== '_self') || link.hasAttribute('download')) return;

        const rawHref = link.getAttribute('href') || '';
        if (
            rawHref === '' ||
            rawHref.startsWith('#') ||
            rawHref.startsWith('javascript:') ||
            rawHref.startsWith('mailto:') ||
            rawHref.startsWith('tel:')
        ) return;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (_) {
            return;
        }
        if (url.origin !== window.location.origin) return;
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash === window.location.hash) return;

        event.preventDefault();
        event.stopPropagation();
        window.open(url.href, '_blank', 'noopener');

        const now = Date.now();
        if (now - this.lastNavigationNoticeAt > 1500 && window.ImgEt?.Utils?.showNotification) {
            this.lastNavigationNoticeAt = now;
            ImgEt.Utils.showNotification('上传继续进行，已在新标签打开页面', 'info');
        }
    }

    /**
     * 处理文件上传
     */
    async handleFiles(files) {
        if (!this.validateEnvironment() || !this.validateFiles(files)) return;

        const validFiles = this.filterValidFiles(files);
        if (validFiles.length === 0) return;

        const uploadableFiles = await this.filterDuplicateFiles(validFiles);
        if (uploadableFiles.length === 0) return;

        const availableSlots = Math.max(0, this.maxFiles - this.uploadRecords.size);
        if (availableSlots <= 0) {
            ImgEt.Utils.showNotification(`待上传队列已达到上限（${this.maxFiles} 个）`, 'warning');
            return;
        }
        const filesToQueue = uploadableFiles.slice(0, availableSlots);
        const skippedByLimit = uploadableFiles.length - filesToQueue.length;
        filesToQueue.forEach(file => this.createUploadRecord(file));
        if (skippedByLimit > 0) {
            ImgEt.Utils.showNotification(`已达到队列上限，跳过 ${skippedByLimit} 个文件`, 'warning');
        }
        if (this.elements.imageInput) {
            this.elements.imageInput.value = '';
        }
        this.renderUploadQueue();
        this.showQueueToast(this.getPendingQueueCount());
    }

    async filterDuplicateFiles(files) {
        const cheapUnique = [];
        const localKeys = new Set();
        let skipped = 0;

        files.forEach(file => {
            const key = [
                file.name || '',
                file.size || 0,
                file.lastModified || 0,
                file.type || ''
            ].join('|');
            if (localKeys.has(key)) {
                skipped++;
                return;
            }
            localKeys.add(key);
            cheapUnique.push(file);
        });

        const totalBytes = cheapUnique.reduce((sum, file) => sum + (file.size || 0), 0);
        const shouldHashBeforeUpload = cheapUnique.length <= UploadManager.CONFIG.DUPLICATE_HASH_MAX_FILES
            && totalBytes <= UploadManager.CONFIG.DUPLICATE_HASH_MAX_TOTAL_BYTES;

        if (!shouldHashBeforeUpload) {
            if (skipped > 0) {
                const message = skipped === 1 ? '已跳过 1 个队列内重复文件' : `已跳过 ${skipped} 个队列内重复文件`;
                ImgEt.Utils.showNotification(message, 'warning', {
                    variant: 'upload',
                    title: message
                });
            }
            return cheapUnique;
        }

        if (!window.crypto?.subtle || typeof File.prototype.arrayBuffer !== 'function') {
            return cheapUnique;
        }

        const candidates = [];
        const localHashes = new Set();

        for (const file of cheapUnique) {
            const hash = await this.computeFileHash(file);
            if (!hash) {
                candidates.push({ file, hash: '' });
                continue;
            }
            if (localHashes.has(hash)) {
                skipped++;
                continue;
            }
            localHashes.add(hash);
            candidates.push({ file, hash });
        }

        const hashes = candidates
            .filter(item => item.hash)
            .map(item => ({ hash: item.hash, name: item.file.name || '', size: item.file.size || 0 }));
        const duplicates = hashes.length > 0 ? await this.checkDuplicateHashes(hashes) : new Set();
        const uploadable = [];

        candidates.forEach(item => {
            if (item.hash && duplicates.has(item.hash)) {
                skipped++;
                return;
            }
            if (item.hash) {
                item.file.__litepicHash = item.hash;
            }
            uploadable.push(item.file);
        });

        if (skipped > 0) {
            const message = skipped === 1 ? '图片已存在，已跳过' : `已跳过 ${skipped} 个重复文件`;
            ImgEt.Utils.showNotification(message, 'warning', {
                variant: 'upload',
                title: message
            });
        }

        if (uploadable.length === 0) {
            this.hideQueueToast();
        }

        return uploadable;
    }

    async computeFileHash(file) {
        try {
            const buffer = await file.arrayBuffer();
            const digest = await crypto.subtle.digest('SHA-1', buffer);
            return Array.from(new Uint8Array(digest))
                .map(byte => byte.toString(16).padStart(2, '0'))
                .join('');
        } catch (error) {
            console.warn('[upload] hash failed:', error);
            return '';
        }
    }

    async checkDuplicateHashes(hashes) {
        try {
            const response = await fetch('/api/v1/duplicate-check', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ hashes }),
            });
            if (!response.ok) return new Set();
            const data = await response.json().catch(() => null);
            const duplicates = data?.duplicates && typeof data.duplicates === 'object'
                ? Object.keys(data.duplicates)
                : [];
            return new Set(duplicates);
        } catch (error) {
            console.warn('[upload] duplicate check failed:', error);
            return new Set();
        }
    }

    getPendingQueueCount() {
        return this.getQueueRecords().filter(record => ['queued', 'failed'].includes(record.state)).length;
    }

    getNotificationHost() {
        let host = document.getElementById('notification');
        if (!host) {
            host = document.createElement('div');
            host.id = 'notification';
            host.className = 'notification-container';
            document.body.appendChild(host);
        }
        return host;
    }

    removeToastItem(item) {
        if (!item) return null;
        if (window.ImgEt?.Utils?.hide) {
            window.ImgEt.Utils.hide(item);
        } else {
            item.remove();
        }
        return null;
    }

    bindPersistentToastClose(item, callback) {
        const closeBtn = item?.querySelector('.notification-close');
        if (!closeBtn) return;
        closeBtn.onclick = () => {
            if (typeof callback === 'function') callback();
            else this.removeToastItem(item);
        };
    }

    showQueueToast(count) {
        if (this.batchUploadToastTimer) {
            window.clearTimeout(this.batchUploadToastTimer);
            this.batchUploadToastTimer = 0;
        }

        if (!this.queueToastItem || !this.queueToastItem.isConnected) {
            this.queueToastItem = ImgEt.Utils.showNotification('', 'success', {
                variant: 'upload',
                title: `已加入队列 ${count} 个文件`,
                icon: 'fa-list-check',
                duration: 0
            });
            this.queueToastItem?.classList.add('upload-queue-toast');
            this.bindPersistentToastClose(this.queueToastItem, () => {
                this.queueToastItem = this.removeToastItem(this.queueToastItem);
            });
            return;
        }

        const title = this.queueToastItem.querySelector('.notification-copy strong');
        if (title) title.textContent = `已加入队列 ${count} 个文件`;
    }

    hideQueueToast() {
        this.queueToastItem = this.removeToastItem(this.queueToastItem);
    }

    showOrUpdateBatchUploadToast(done = 0, total = 0, state = 'running') {
        if (total <= 1) return;
        const host = this.getNotificationHost();
        if (!host) return;

        if (this.batchUploadToastTimer) {
            window.clearTimeout(this.batchUploadToastTimer);
            this.batchUploadToastTimer = 0;
        }

        if (!this.batchUploadToastItem || !this.batchUploadToastItem.isConnected) {
            this.batchUploadToastItem = document.createElement('div');
            this.batchUploadToastItem.className = 'notification-item info notification-upload upload-batch-toast';
            this.batchUploadToastItem.setAttribute('role', 'status');
            this.batchUploadToastItem.innerHTML = `
                <i class="fa-light fa-spinner-third fa-spin" aria-hidden="true"></i>
                <div class="notification-copy">
                    <strong class="upload-batch-toast-title"></strong>
                    <span class="upload-batch-toast-detail"></span>
                </div>
                <button class="notification-close" aria-label="关闭通知">
                    <i class="fa-light fa-times"></i>
                </button>
            `;
            host.appendChild(this.batchUploadToastItem);
            this.bindPersistentToastClose(this.batchUploadToastItem, () => {
                if (this.batchUploadToastTimer) {
                    window.clearTimeout(this.batchUploadToastTimer);
                    this.batchUploadToastTimer = 0;
                }
                this.batchUploadToastItem = this.removeToastItem(this.batchUploadToastItem);
            });
            requestAnimationFrame(() => this.batchUploadToastItem?.classList.add('show'));
        }

        const item = this.batchUploadToastItem;
        const icon = item.querySelector(':scope > i');
        const title = item.querySelector('.upload-batch-toast-title');
        const detail = item.querySelector('.upload-batch-toast-detail');
        item.classList.remove('success', 'warning', 'error', 'info');

        if (state === 'complete') {
            item.classList.add(this.batchFailed > 0 ? 'warning' : 'success');
            if (icon) {
                icon.className = this.batchFailed > 0
                    ? 'fa-light fa-triangle-exclamation'
                    : 'fa-light fa-check-circle';
            }
            if (title) title.textContent = (this.batchFailed > 0 || this.batchSkipped > 0) ? '上传完成' : '全部上传成功';
            if (detail) {
                detail.textContent = this.batchFailed > 0
                    ? `成功 ${this.batchSucceeded} 个，失败 ${this.batchFailed} 个`
                    : this.batchSkipped > 0
                        ? `成功 ${this.batchSucceeded} 个，跳过 ${this.batchSkipped} 个`
                        : '';
            }
            this.batchUploadToastTimer = window.setTimeout(() => {
                this.batchUploadToastItem = this.removeToastItem(this.batchUploadToastItem);
                this.batchUploadToastTimer = 0;
            }, 3000);
            return;
        }

        item.classList.add('info');
        if (icon) icon.className = 'fa-light fa-spinner-third fa-spin';
        if (title) title.textContent = done > 0 ? `${done}/${total} 成功` : `0/${total} 上传中`;
        if (detail) detail.textContent = this.batchFailed > 0 ? `失败 ${this.batchFailed} 个` : '';
    }

    hideBatchUploadToast() {
        if (this.batchUploadToastTimer) {
            window.clearTimeout(this.batchUploadToastTimer);
            this.batchUploadToastTimer = 0;
        }
        this.batchUploadToastItem = this.removeToastItem(this.batchUploadToastItem);
    }

    startUploadRun(records, showBatchProgress = false) {
        const pendingRecords = records.filter(record => record && ['queued', 'failed'].includes(record.state));
        if (pendingRecords.length === 0) {
            ImgEt.Utils.showNotification('没有待上传的文件', 'warning');
            return;
        }
        if (this.isBatchUploading()) {
            ImgEt.Utils.showNotification('上传正在进行中，请等待当前任务完成', 'warning');
            return;
        }

        this.hideQueueToast();
        this.batchRunId += 1;
        this.batchProgressVisible = !!showBatchProgress;
        if (this.batchProgressVisible) {
            this.resetProgressArea();
        } else {
            this.progressItem = null;
            if (this.elements.uploadProgress) {
                this.elements.uploadProgress.classList.remove('active');
                this.elements.uploadProgress.classList.add('is-hidden');
                this.elements.uploadProgress.style.display = '';
                this.elements.uploadProgress.innerHTML = '';
            }
            this.updateHeaderUploadProgress(0, true);
        }

        this.batchTotal = pendingRecords.length;
        this.batchCompleted = 0;
        this.batchSucceeded = 0;
        this.batchSkipped = 0;
        this.batchFailed = 0;
        this.batchNotified = false;
        this.activeUploads = 0;
        this.completedUploads = [];
        this.activeRunIds = new Set(pendingRecords.map(record => record.id));
        this.uploadQueue = pendingRecords.map(record => {
            record.state = 'queued';
            record.loaded = 0;
            record.error = '';
            return record;
        });

        if (this.batchTotal > 0) {
            this.renderUploadQueue();
            this.updateBatchProgressUI();
            this.processUploadQueue();
        }
    }

    uploadAllQueued() {
        this.startUploadRun(Array.from(this.uploadRecords.values()), true);
    }

    uploadSingleQueued(id) {
        const record = this.uploadRecords.get(Number(id));
        if (!record) return;
        this.startUploadRun([record], false);
    }

    clearUploadQueue() {
        if (this.isBatchUploading()) {
            ImgEt.Utils.showNotification('上传进行中，暂不能清空队列', 'warning');
            return;
        }
        this.uploadRecords.forEach(record => {
            if (record.previewUrl) {
                URL.revokeObjectURL(record.previewUrl);
            }
        });
        this.uploadRecords.clear();
        this.uploadQueue = [];
        this.activeRunIds.clear();
        this.renderUploadQueue();
        this.hideQueueToast();
        this.hideBatchUploadToast();
    }

    removeQueuedRecord(id) {
        const record = this.uploadRecords.get(Number(id));
        if (!record) return;
        if (record.state === 'uploading' || record.state === 'processing') {
            ImgEt.Utils.showNotification('上传中的文件不能移除', 'warning');
            return;
        }
        if (record.previewUrl) {
            URL.revokeObjectURL(record.previewUrl);
        }
        this.uploadRecords.delete(record.id);
        this.uploadQueue = this.uploadQueue.filter(item => item.id !== record.id);
        this.activeRunIds.delete(record.id);
        this.renderUploadQueue();
        const count = this.getPendingQueueCount();
        if (count > 0) {
            this.showQueueToast(count);
        } else {
            this.hideQueueToast();
        }
    }

    handleQueueAction(event) {
        const button = event.target.closest('[data-queue-action]');
        if (!button) return;

        const id = button.dataset.recordId;
        if (button.dataset.queueAction === 'upload') {
            this.uploadSingleQueued(id);
        } else if (button.dataset.queueAction === 'remove') {
            this.removeQueuedRecord(id);
        }
    }

    /**
     * 验证运行环境
     */
    validateEnvironment() {
        if (!window.ImgEt?.Utils) {
            console.error('ImgEt.Utils not loaded');
            if (window.ImgEt?.Utils?.showNotification) {
                window.ImgEt.Utils.showNotification('系统错误，请刷新页面重试', 'error');
            }
            return false;
        }
        return true;
    }

    /**
     * 验证文件
     */
    validateFiles(files) {
        if (!files?.length) {
            ImgEt.Utils.showNotification('请选择要上传的图片', 'error');
            return false;
        }
        return true;
    }

    /**
     * 过滤有效文件
     */
    filterValidFiles(files) {
        const maxSizeLabel = this.formatSize(this.maxSize);
        const validFiles = Array.from(files).filter(file => {
            const extension = this.getFileExtension(file.name);
            if (!extension || (this.allowedExtensions.size > 0 && !this.allowedExtensions.has(extension))) {
                const allowedLabel = this.getAllowedExtensionsLabel();
                ImgEt.Utils.showNotification(`${file.name} 不在允许上传格式内（${allowedLabel}）`, 'error');
                return false;
            }

            if (file.type && !file.type.startsWith('image/')) {
                ImgEt.Utils.showNotification(`${file.name} 不是有效的图片文件`, 'error');
                return false;
            }
            
            if (file.size > this.maxSize) {
                ImgEt.Utils.showNotification(`${file.name} 超过大小限制(${maxSizeLabel})`, 'error');
                return false;
            }
            return true;
        });

        if (validFiles.length === 0) {
            ImgEt.Utils.showNotification('没有可上传的有效图片', 'error');
        }

        return validFiles;
    }

    getFileExtension(filename) {
        const name = String(filename || '').trim();
        const dot = name.lastIndexOf('.');
        if (dot < 0 || dot === name.length - 1) return '';
        return name.slice(dot + 1).toLowerCase();
    }

    getAllowedExtensionsLabel() {
        if (this.allowedExtensions.size === 0) return '当前未配置';
        return Array.from(this.allowedExtensions)
            .map(ext => `.${ext.toUpperCase()}`)
            .join(' / ');
    }

    createUploadRecord(file) {
        const id = ++this.uploadSequence;
        const record = {
            id,
            file,
            name: file.name || `image-${id}`,
            extension: this.getFileExtension(file.name),
            previewUrl: '',                 // 异步生成 128px 缩略图填上（见 generateThumbnail）
            hash: file.__litepicHash || '',
            loaded: 0,
            total: Number.isFinite(file.size) && file.size > 0 ? file.size : 1,
            state: 'queued',
            error: ''
        };
        this.uploadRecords.set(id, record);

        // 关键：不要直接把 URL.createObjectURL(file) 当 src — 那是个指向原始
        // 4032×3024 等全分辨率位图的 blob URL，浏览器会真的把整张解码进内存
        // 哪怕 CSS 只显示 46×46。50 张手机照片直接占爆 GPU/RAM，所以我们
        // 用 canvas 离屏缩成 128 px JPEG blob 再用作预览，原图 record.file
        // 不动（上传时仍是原始 file 对象）。
        if (file.type && file.type.startsWith('image/')) {
            this.generateThumbnail(file).then((thumbUrl) => {
                if (!this.uploadRecords.has(id)) return;     // 用户已经移除
                record.previewUrl = thumbUrl;
                this.updateQueueRowPreview(id, thumbUrl);
            }).catch(() => {
                // 缩略图失败不影响上传 — 留空，UI 会 fallback 到默认 file-image 图标
            });
        }
        return record;
    }

    /**
     * 把本地 File 离屏缩成 ≤128 px 的 JPEG blob URL，用于队列预览。
     * 用 createImageBitmap 异步解码（不阻塞主线程），canvas 缩放后转 blob。
     * 比 `URL.createObjectURL(file)` 直接当 src 节省 99%+ 内存。
     */
    async generateThumbnail(file) {
        const MAX = 128;
        let bitmap = null;
        try {
            // createImageBitmap 是 worker-friendly 的解码方式，比 new Image()
            // + onload 快且不会污染主线程。Safari 14+ / Chrome 50+ 全部支持。
            bitmap = await createImageBitmap(file);
        } catch (e) {
            // 极少数老格式（HEIC 等）createImageBitmap 不认 — 回退到原 file 的
            // object URL，至少能显示，但浏览器会承受全分辨率代价（罕见情况
            // 不做优化，跟旧行为持平）
            return URL.createObjectURL(file);
        }

        const ratio = Math.min(MAX / bitmap.width, MAX / bitmap.height, 1);
        const w = Math.max(1, Math.round(bitmap.width * ratio));
        const h = Math.max(1, Math.round(bitmap.height * ratio));

        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d', { alpha: false });
        ctx.imageSmoothingQuality = 'medium';
        ctx.drawImage(bitmap, 0, 0, w, h);
        bitmap.close?.();        // 立即释放原始解码位图（这是大头）

        return await new Promise((resolve) => {
            canvas.toBlob((blob) => {
                resolve(blob ? URL.createObjectURL(blob) : '');
            }, 'image/jpeg', 0.78);
        });
    }

    /**
     * 在不重建整张队列 DOM 的前提下，把刚生成的缩略图 src 注入到对应行。
     * 配合 createUploadRecord 异步流程使用。
     */
    updateQueueRowPreview(recordId, previewUrl) {
        if (!previewUrl || !this.elements.queueList) return;
        const row = this.elements.queueList.querySelector(`[data-record-id="${recordId}"]`);
        if (!row) return;
        const thumbBox = row.querySelector('.upload-queue-thumb');
        if (!thumbBox) return;
        // 只替换 thumbBox 内容，不动外层 article — 不会触发整列 re-decode
        thumbBox.innerHTML = `<img src="${previewUrl}" alt="" loading="lazy" decoding="async" width="46" height="46">`;
    }

    getQueueRecords() {
        return Array.from(this.uploadRecords.values());
    }

    getQueueStatus(record) {
        const status = {
            queued: ['等待上传', 'is-queued', 'fa-clock'],
            uploading: ['上传中', 'is-uploading', 'fa-spinner fa-spin'],
            processing: ['处理中', 'is-processing', 'fa-gear fa-spin'],
            done: ['已完成', 'is-done', 'fa-check'],
            duplicate: ['已跳过重复', 'is-duplicate', 'fa-copy'],
            failed: [record.error || '失败', 'is-failed', 'fa-triangle-exclamation']
        }[record.state] || ['等待上传', 'is-queued', 'fa-clock'];

        return { text: status[0], className: status[1], icon: status[2] };
    }

    renderUploadQueue() {
        const panel = this.elements.queuePanel;
        const list = this.elements.queueList;
        if (!panel || !list) return;

        const records = this.getQueueRecords();
        const pendingCount = records.filter(record => ['queued', 'failed'].includes(record.state)).length;
        const active = this.isBatchUploading();
        panel.classList.toggle('is-empty', records.length === 0);
        panel.classList.toggle('is-active', records.length > 0);

        if (this.elements.queueCount) {
            this.elements.queueCount.textContent = String(records.length);
        }
        if (this.elements.uploadAllBtn) {
            this.elements.uploadAllBtn.disabled = pendingCount === 0 || active;
        }
        if (this.elements.clearQueueBtn) {
            this.elements.clearQueueBtn.disabled = records.length === 0 || active;
        }

        if (records.length === 0) {
            list.innerHTML = `
                <div class="upload-queue-empty">
                    <i class="fa-light fa-images"></i>
                    <span>选择图片后会先加入队列，不会自动上传</span>
                </div>
            `;
            return;
        }

        list.innerHTML = records.map(record => {
            const status = this.getQueueStatus(record);
            const progress = Math.round(this.getRecordProgress(record) * 100);
            const removable = record.state !== 'uploading' && record.state !== 'processing';
            const uploadable = ['queued', 'failed'].includes(record.state) && !active;
            // 缩略图属性：
            //   loading="lazy"   — 滚出视口的图不解码，滑回来才解
            //   decoding="async" — 解码丢到 worker 线程，不阻塞滚动
            //   width/height     — 提前占好布局空间，避免 CLS / 重排
            const thumb = record.previewUrl
                ? `<img src="${record.previewUrl}" alt="" loading="lazy" decoding="async" width="46" height="46">`
                : `<i class="fa-light fa-file-image"></i>`;
            return `
                <article class="upload-queue-item ${status.className}" data-record-id="${record.id}">
                    <div class="upload-queue-thumb">${thumb}</div>
                    <div class="upload-queue-meta">
                        <strong title="${escapeHtml(record.name)}">${escapeHtml(record.name)}</strong>
                        <span>${escapeHtml(this.formatSize(record.total))} · .${escapeHtml((record.extension || 'IMG').toUpperCase())}</span>
                    </div>
                    <div class="upload-queue-state">
                        <span><i class="fa-light ${status.icon}"></i>${escapeHtml(status.text)}</span>
                        <em>${progress}%</em>
                    </div>
                    <div class="upload-queue-progress" aria-hidden="true"><span style="width:${progress}%"></span></div>
                    <div class="upload-queue-row-actions">
                        <button type="button" data-queue-action="upload" data-record-id="${record.id}" ${uploadable ? '' : 'disabled'} title="上传此文件">
                            <i class="fa-light fa-cloud-arrow-up"></i>
                        </button>
                        <button type="button" data-queue-action="remove" data-record-id="${record.id}" ${removable ? '' : 'disabled'} title="移除此文件">
                            <i class="fa-light fa-xmark"></i>
                        </button>
                    </div>
                </article>
            `;
        }).join('');
    }

    /**
     * 单行原地更新 — 不动任何 <img>，只改 class / 状态文字 / 进度条。
     * 替代旧的"settle 后整张 list.innerHTML 重建"做法，避免 50 张图全
     * 量重建 50 次造成的滚动卡顿和缩略图重新解码。
     */
    updateQueueRow(record) {
        const list = this.elements.queueList;
        if (!list || !record) return;
        const row = list.querySelector(`[data-record-id="${record.id}"]`);
        if (!row) {
            // 不存在 — 触发一次完整 render（添加 / 移除场景）
            this.renderUploadQueue();
            return;
        }
        const status = this.getQueueStatus(record);
        const progress = Math.round(this.getRecordProgress(record) * 100);
        const active = this.isBatchUploading();
        const removable = record.state !== 'uploading' && record.state !== 'processing';
        const uploadable = ['queued', 'failed'].includes(record.state) && !active;

        // 保留所有原 class，只重写 is-* 状态修饰符
        row.className = 'upload-queue-item ' + status.className;

        const stateBox = row.querySelector('.upload-queue-state');
        if (stateBox) {
            stateBox.innerHTML = `<span><i class="fa-light ${status.icon}"></i>${escapeHtml(status.text)}</span><em>${progress}%</em>`;
        }
        const bar = row.querySelector('.upload-queue-progress > span');
        if (bar) bar.style.width = progress + '%';

        const uploadBtn = row.querySelector('button[data-queue-action="upload"]');
        if (uploadBtn) uploadBtn.disabled = !uploadable;
        const removeBtn = row.querySelector('button[data-queue-action="remove"]');
        if (removeBtn) removeBtn.disabled = !removable;
    }

    /**
     * 队列头部按钮（一键上传 / 清空）的可用状态 — 跟整张 list 解耦，
     * 单文件 settle 时刷它就够了，不用整张重建。
     */
    updateQueueHeaderState() {
        const records = this.getQueueRecords();
        const pendingCount = records.filter(r => ['queued', 'failed'].includes(r.state)).length;
        const active = this.isBatchUploading();
        if (this.elements.queueCount) this.elements.queueCount.textContent = String(records.length);
        if (this.elements.uploadAllBtn) this.elements.uploadAllBtn.disabled = pendingCount === 0 || active;
        if (this.elements.clearQueueBtn) this.elements.clearQueueBtn.disabled = records.length === 0 || active;
    }

    /**
     * 重置进度区域
     */
    resetProgressArea() {
        this.elements.uploadProgress.innerHTML = '';
        this.progressItem = this.createBatchProgressItem();
        this.elements.uploadProgress.appendChild(this.progressItem);
        this.elements.uploadProgress.classList.remove('is-hidden');
        this.elements.uploadProgress.style.display = 'block';
        requestAnimationFrame(() => this.elements.uploadProgress.classList.add('active'));
        this.updateHeaderUploadProgress(0, true);
    }

    hideProgressArea(runId) {
        if (runId !== this.batchRunId || !this.elements.uploadProgress) return;
        this.elements.uploadProgress.classList.remove('active');
        window.setTimeout(() => {
            if (runId !== this.batchRunId || !this.elements.uploadProgress) return;
            this.elements.uploadProgress.classList.add('is-hidden');
            this.elements.uploadProgress.style.display = '';
            this.elements.uploadProgress.innerHTML = '';
            this.progressItem = null;
        }, UploadManager.CONFIG.FADE_DURATION);
    }

    /**
     * 上传单个文件
     */
    uploadSingleFile(record) {
        if (!record || !record.file) return;
        record.state = 'uploading';
        record.loaded = 0;
        this.activeUploads += 1;
        this.updateBatchProgressUI();

        const settle = (outcome = false) => {
            const success = outcome === true || outcome === 'success';
            const duplicate = outcome === 'duplicate';
            this.activeUploads = Math.max(0, this.activeUploads - 1);
            this.batchCompleted += 1;
            if (success) {
                this.batchSucceeded += 1;
                record.state = 'done';
            } else if (duplicate) {
                this.batchSkipped += 1;
                record.state = 'duplicate';
            } else {
                this.batchFailed += 1;
                record.state = 'failed';
            }
            record.loaded = record.total;
            // 关键改动：只更新该行 + 头部按钮态，不再整张 list.innerHTML 重建。
            // 之前的写法在 50 张队列下，每完成一张就重建整张 50 行 + 重新解码
            // 50 张缩略图，是滚动卡顿和"空白闪烁"的主要根因。
            this.updateQueueRow(record);
            this.updateQueueHeaderState();
            this.updateBatchProgressUI();
            this.processUploadQueue();
            this.checkUploadComplete();
        };

        const xhr = this.createUploadRequest(record.file, record, settle);
        xhr.send(this.createFormData(record.file));
    }

    /**
     * 创建进度条元素
     */
    createBatchProgressItem() {
        const item = document.createElement('div');
        item.className = 'progress-item progress-item-batch';
        item.innerHTML = `
            <div class="progress-header">
                <span class="filename">批量上传准备中</span>
                <span class="progress-percent">0%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-bar-inner"></div>
            </div>
            <div class="progress-status">
                <span class="progress-text">
                    <i class="fa-light fa-spinner fa-spin text-success"></i>
                    <span class="text-success">准备上传...</span>
                </span>
                <span class="progress-count">0/0</span>
            </div>
        `;
        return item;
    }

    /**
     * 创建上传请求
     */
    createUploadRequest(file, record, settle) {
        const xhr = new XMLHttpRequest();

        // 配置请求
        xhr.timeout = this.getTimeoutForFileSize(file.size);
        xhr.open('POST', '/api/v1');

        // 绑定事件处理
        this.bindUploadEvents(xhr, file, record, settle);

        return xhr;
    }

    getTimeoutForFileSize(fileSize) {
        if (fileSize <= UploadManager.CONFIG.TIMEOUT_SMALL_THRESHOLD) {
            return UploadManager.CONFIG.TIMEOUT_SMALL;
        }
        if (fileSize <= UploadManager.CONFIG.TIMEOUT_MEDIUM_THRESHOLD) {
            return UploadManager.CONFIG.TIMEOUT_MEDIUM;
        }
        return UploadManager.CONFIG.TIMEOUT_LARGE;
    }

    processUploadQueue() {
        while (this.activeUploads < this.maxConcurrent && this.uploadQueue.length > 0) {
            const record = this.uploadQueue.shift();
            this.uploadSingleFile(record);
        }
    }

    /**
     * 绑定上传事件
     */
    bindUploadEvents(xhr, file, record, settle) {
        let settled = false;
        const settleOnce = (outcome = false) => {
            if (settled) return;
            settled = true;
            settle(outcome);
        };

        // 错误处理
        xhr.addEventListener('timeout', () => {
            this.handleUploadError(record, `上传超时（${Math.round(xhr.timeout / 1000)} 秒）`);
            settleOnce(false);
        });
        xhr.addEventListener('error', () => {
            this.handleUploadError(record, '连接中断，请检查上传大小限制或稍后重试');
            settleOnce(false);
        });
        xhr.addEventListener('abort', () => {
            this.handleUploadError(record, '上传取消');
            settleOnce(false);
        });

        // 进度处理
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                this.updateProgress(record, e.loaded, e.total);
            }
        });

        xhr.upload.addEventListener('load', () => {
            this.showServerProcessing(record);
        });

        // 完成处理
        xhr.addEventListener('load', () => {
            const success = this.handleUploadComplete(xhr, file, record);
            settleOnce(success);
        });
    }

    /**
     * 创建表单数据
     */
    createFormData(file) {
        const formData = new FormData();
        formData.append('image[]', file);  // 直接上传原始文件,不添加压缩参数
        // 相册下拉选了就带上,后端 api/upload.php 会把这次上传的所有
        // 文件加到这个相册。空值就忽略 — 上传不会因为相册问题失败。
        const albumPicker = document.querySelector('[data-album-picker]');
        const slug = albumPicker?.value?.trim() || '';
        if (slug !== '') formData.append('album', slug);
        return formData;
    }

    /**
     * 更新上传进度
     */
    updateProgress(record, loaded, total) {
        if (!record) return;
        record.state = 'uploading';
        record.loaded = loaded;
        record.total = total > 0 ? total : record.total;
        // 单行原地更新（更新该行的状态文字 + 进度条），避免每次进度回调
        // 都触发整个 list.innerHTML 重建造成滚动卡顿
        this.updateQueueRow(record);
        this.updateBatchProgressUI();
    }

    showServerProcessing(record) {
        if (!record) return;
        record.state = 'processing';
        record.loaded = record.total;
        const tasks = [];
        if (this.autoCompressEnabled) tasks.push('开始压缩');
        if (this.autoConvertEnabled) {
            const formatLabel = { webp: 'WebP', avif: 'AVIF', jpg: 'JPG', png: 'PNG' }[this.autoConvertFormat] || this.autoConvertFormat.toUpperCase();
            tasks.push(`开始转换 ${formatLabel}`);
        }
        record.processingText = tasks.length > 0
            ? `服务器处理中：${tasks.join('，')}`
            : '服务器处理中';
        this.updateQueueRow(record);
        this.updateBatchProgressUI();
    }

    getRecordProgress(record) {
        if (!record) return 0;
        if (record.state === 'done' || record.state === 'failed' || record.state === 'duplicate') return 1;
        if (record.state === 'processing') return 0.95;
        if (record.state === 'uploading') {
            const total = record.total > 0 ? record.total : 1;
            return Math.max(0, Math.min(0.9, (record.loaded / total) * 0.9));
        }
        return 0;
    }

    getBatchPercent() {
        const records = Array.from(this.uploadRecords.values())
            .filter(record => this.activeRunIds.has(record.id));
        if (records.length === 0) return 0;
        const progress = records.reduce((sum, record) => sum + this.getRecordProgress(record), 0) / records.length;
        return Math.max(0, Math.min(100, Math.round(progress * 100)));
    }

    updateBatchProgressUI() {
        const percent = this.getBatchPercent();
        const progressBar = this.progressItem?.querySelector('.progress-bar-inner') || null;
        const progressText = this.progressItem?.querySelector('.progress-text') || null;
        const progressPercent = this.progressItem?.querySelector('.progress-percent') || null;
        const progressCount = this.progressItem?.querySelector('.progress-count') || null;
        const filename = this.progressItem?.querySelector('.filename') || null;
        const isFinished = this.batchTotal > 0 && this.batchCompleted >= this.batchTotal;

        if (this.progressItem) {
            this.progressItem.classList.toggle('is-complete', isFinished && this.batchFailed === 0);
            this.progressItem.classList.toggle('is-failed', isFinished && this.batchFailed > 0);
            if (progressBar) {
                progressBar.style.width = `${percent}%`;
                progressBar.classList.toggle('success', isFinished && this.batchFailed === 0);
                progressBar.classList.toggle('danger', isFinished && this.batchFailed > 0);
            }
        }
        if (progressPercent) {
            progressPercent.textContent = `${percent}%`;
        }
        if (progressCount) {
            progressCount.textContent = `${this.batchCompleted}/${this.batchTotal}`;
        }

        const activeNames = Array.from(this.uploadRecords.values())
            .filter(record => this.activeRunIds.has(record.id))
            .filter(record => record.state === 'uploading' || record.state === 'processing')
            .slice(0, this.maxConcurrent)
            .map(record => record.name);
        if (filename) {
            filename.textContent = activeNames.length > 0 ? activeNames.join(' / ') : '批量上传';
        }

        let icon = 'fa-spinner fa-spin text-success';
        let textClass = 'text-success';
        let message = `上传中 · 并发 ${this.activeUploads}`;

        if (this.batchCompleted >= this.batchTotal && this.batchTotal > 0) {
            if (this.batchFailed > 0) {
                icon = 'fa-triangle-exclamation text-danger';
                textClass = 'text-danger';
                message = `完成 ${this.batchSucceeded} 个，失败 ${this.batchFailed} 个`;
            } else if (this.batchSkipped > 0) {
                icon = 'fa-copy text-warning';
                textClass = 'text-warning';
                message = `完成 ${this.batchSucceeded} 个，跳过 ${this.batchSkipped} 个`;
            } else {
                icon = 'fa-check-circle text-success';
                textClass = 'text-success';
                message = '全部上传完成';
            }
        } else if (Array.from(this.uploadRecords.values()).some(record => this.activeRunIds.has(record.id) && record.state === 'processing')) {
            icon = 'fa-gear fa-spin text-primary';
            textClass = 'text-primary';
            message = '上传完成，服务器处理中';
        } else if (this.batchCompleted === 0 && this.activeUploads === 0) {
            message = '准备上传...';
        }

        if (progressText) {
            progressText.innerHTML = `
                <i class="fa-light ${icon}"></i>
                <span class="${textClass}">${escapeHtml(message)}</span>
            `;
        }

        if (this.batchTotal > 1 && !this.batchNotified) {
            this.showOrUpdateBatchUploadToast(this.batchSucceeded, this.batchTotal, 'running');
        }

        this.updateHeaderUploadProgress(percent, this.batchCompleted < this.batchTotal);
    }

    updateHeaderUploadProgress(percent, active) {
        const button = this.headerUploadButton;
        if (!button) return;

        const safePercent = Math.max(0, Math.min(100, Math.round(percent || 0)));
        button.style.setProperty('--upload-progress', `${safePercent}%`);

        if (active) {
            button.classList.add('is-uploading');
            button.classList.remove('is-upload-complete', 'is-upload-error');
            if (this.headerUploadIcon) {
                this.headerUploadIcon.className = this.headerUploadDefaultIcon;
                this.headerUploadIcon.hidden = true;
                this.headerUploadIcon.setAttribute('aria-hidden', 'true');
            }
            if (this.headerUploadLoader) {
                this.headerUploadLoader.hidden = false;
            }
            if (this.headerUploadLabel) {
                this.headerUploadLabel.textContent = `${safePercent}%`;
            }
            return;
        }

        button.classList.remove('is-uploading');
        button.classList.toggle('is-upload-error', this.batchFailed > 0);
        button.classList.toggle('is-upload-complete', this.batchFailed === 0 && this.batchTotal > 0);
        button.style.setProperty('--upload-progress', '100%');
        if (this.headerUploadIcon) {
            this.headerUploadIcon.hidden = false;
            this.headerUploadIcon.setAttribute('aria-hidden', 'true');
            this.headerUploadIcon.className = this.batchFailed > 0
                ? 'fa-light fa-triangle-exclamation'
                : 'fa-light fa-check';
        }
        if (this.headerUploadLoader) {
            this.headerUploadLoader.hidden = true;
        }
        if (this.headerUploadLabel) {
            this.headerUploadLabel.textContent = this.batchFailed > 0 ? '失败' : '完成';
        }

        const resetRunId = this.batchRunId;
        window.setTimeout(() => {
            if (resetRunId !== this.batchRunId) return;
            button.classList.remove('is-upload-complete', 'is-upload-error');
            button.style.removeProperty('--upload-progress');
            if (this.headerUploadIcon) {
                this.headerUploadIcon.className = this.headerUploadDefaultIcon;
                this.headerUploadIcon.hidden = false;
                this.headerUploadIcon.setAttribute('aria-hidden', 'true');
            }
            if (this.headerUploadLoader) {
                this.headerUploadLoader.hidden = true;
            }
            if (this.headerUploadLabel) {
                this.headerUploadLabel.textContent = this.headerUploadDefaultText;
            }
        }, 2200);
    }

    /**
     * 处理上传完成
     */
    handleUploadComplete(xhr, file, record) {
        try {
            let response = null;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (_) {
                // 兼容响应前面混入少量非 JSON 文本的情况
                const raw = String(xhr.responseText || '');
                const start = raw.indexOf('{');
                const end = raw.lastIndexOf('}');
                if (start >= 0 && end > start) {
                    response = JSON.parse(raw.slice(start, end + 1));
                } else {
                    throw new Error('服务器返回非 JSON 响应，请检查后端日志');
                }
            }

            if (xhr.status !== 200) {
                if (xhr.status === 413) {
                    throw new Error('请求体过大，请检查 PHP post_max_size / Nginx client_max_body_size');
                }
                throw new Error(response?.message || `服务器错误 (${xhr.status})`);
            }

            if (!response.results) {
                throw new Error(response.message || '上传失败');
            }

            const result = response.results[0] || null;
            if (result && result.status === 'duplicate') {
                this.handleUploadDuplicate(record, result);
                return 'duplicate';
            }
            if (!result || result.status !== 'success') {
                throw new Error((result && result.message) || response.message || '上传失败');
            }

            // 修改这里：使用原始文件名显示成功消息
            this.showUploadSuccess(file.name, record, result);
            this.completedUploads.push(result);

            // 服务端可能已把缩略图 / 压缩 / WebP / AVIF 任务入队，由 ImageProcessor::drain
            // 在响应送达后异步处理。把这条记录扔进 poller，每 2.5s 拉一次状态，
            // 处理完成后把行状态从 "已上传" 升级为 "已处理"（含 webp/avif/缩略图标记）
            if (result.processing && result.processing.queued && result.filename) {
                record.serverFilename = String(result.filename);
                record.serverState = 'post-processing';
                this.startPostProcessPoll();
            }
            return true;

        } catch (err) {
            console.error('Upload error:', err);
            this.handleUploadError(record, err.message);
            return false;
        }
    }

    /**
     * 异步处理状态轮询（poller）。
     *
     * 时序模型(为什么要指数退避):
     *   - upload XHR 在 ~100ms 返回响应
     *   - 服务器在 fastcgi_finish_request 之后才启动 drain,所以 0ms 时探查到的
     *     还是 'pending'。第一次轮询略等一下能赶上 'processing' / 'done'。
     *   - 缩略图(用户当前唯一的后台任务)通常 200-500ms 完成
     *   - 重活(WebP/AVIF/水印/远程同步)可达 2-30s
     *
     * 节奏:
     *   起点 400ms → 700 → 1100 → 1700 → 2500ms 封顶。
     *   缩略图 case 通常 1-2 次内就拿到 'done'(总等待 < 1s),
     *   重活 case 自动退到 2.5s,不增加服务器压力。
     *
     * 单实例:一个 poller 服务于所有正在 post-processing 的 record;
     * Queue 全空时自动停。
     */
    startPostProcessPoll() {
        if (this.postProcessPollTimer) return;

        // 退避序列 — 经验值,头几次密集追快任务,尾部稳定在 2.5s
        const POLL_INTERVALS = [400, 700, 1100, 1700, 2500];
        let pollIndex = 0;

        const tick = async () => {
            const pending = this.getQueueRecords().filter(
                (r) => r.serverState === 'post-processing' && r.serverFilename
            );
            if (pending.length === 0) {
                if (this.postProcessPollTimer) {
                    clearTimeout(this.postProcessPollTimer);
                    this.postProcessPollTimer = 0;
                }
                return;
            }

            // 只查这一轮在排队的 id（最多 100 — server 端上限同步）
            const ids = pending.slice(0, 100).map((r) => r.serverFilename);
            try {
                const resp = await fetch('/api/v1/image-status?ids=' + encodeURIComponent(ids.join(',')), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                const data = await resp.json().catch(() => null);
                if (!data || data.status !== 'success' || !Array.isArray(data.items)) return;

                const byId = new Map(data.items.map((it) => [it.filename, it]));
                pending.forEach((record) => {
                    const item = byId.get(record.serverFilename);
                    if (!item) return;

                    // queue_state === 'done' 意味着所有 post-process 工作已完成
                    if (item.queue_state === 'done') {
                        record.serverState = 'done-processed';
                        record.serverProcessed = {
                            currentFilename: item.current_filename,
                            currentUrl: item.current_url,
                            thumbUrl: item.thumb_url,
                            hasWebp: !!item.has_webp,
                            hasAvif: !!item.has_avif,
                            hasThumbnail: !!item.has_thumbnail,
                        };
                        // 行内显示一条进度文字 — 用 .upload-queue-item 上的 state 标志
                        const list = this.elements.queueList;
                        const row = list?.querySelector(`[data-record-id="${record.id}"]`);
                        if (row) {
                            const stateBox = row.querySelector('.upload-queue-state span');
                            if (stateBox) {
                                const tags = [];
                                if (item.has_webp) tags.push('WebP');
                                if (item.has_avif) tags.push('AVIF');
                                if (item.has_thumbnail) tags.push('缩略图');
                                const suffix = tags.length ? `（${tags.join(' · ')}）` : '';
                                stateBox.innerHTML = `<i class="fa-light fa-check-double"></i>已处理${suffix}`;
                            }
                        }
                    } else if (item.queue_state === 'processing') {
                        // 中间态：服务端正在跑某一项工作
                        const list = this.elements.queueList;
                        const row = list?.querySelector(`[data-record-id="${record.id}"]`);
                        const stateBox = row?.querySelector('.upload-queue-state span');
                        if (stateBox) {
                            stateBox.innerHTML = '<i class="fa-light fa-spinner fa-spin"></i>处理中...';
                        }
                    }
                });
            } catch (e) {
                // 静默 — 网络抖动 / 后端临时错都不该让 UI 卡住
                console.warn('[post-process poll]', e);
            }
        };

        // setTimeout 链 + 退避,而不是 setInterval — 因为我们要每次根据
        // pollIndex 取不同间隔。tick() 负责"如果还有 pending 就排下一次"。
        const scheduleNext = () => {
            const interval = POLL_INTERVALS[Math.min(pollIndex, POLL_INTERVALS.length - 1)];
            pollIndex += 1;
            this.postProcessPollTimer = window.setTimeout(async () => {
                await tick();
                // tick() 会在 pending 空时把 timer 清掉；只有还在跑才继续排
                if (this.postProcessPollTimer) scheduleNext();
            }, interval);
        };
        scheduleNext();
    }

    /**
     * 显示上传成功
     */
    getSkipReasonText(reason, type = 'compress') {
        const map = {
            disabled: type === 'compress' ? '未开启自动压缩' : '未开启自动转 WebP',
            webp_enabled: '已启用自动转 WebP，压缩已跳过',
            unsupported_format: '格式不支持',
            missing_file: '源文件不存在',
            size_unavailable: '无法读取文件大小',
            size_unavailable_after: '处理后大小不可读',
            compress_failed: '压缩失败',
            not_reduced: '体积未减小，已跳过',
            convert_failed: '转换失败',
            output_missing: '输出文件未生成'
        };
        return map[reason] || '处理跳过';
    }

    formatSize(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let unitIndex = 0;
        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex++;
        }
        const digits = value >= 100 ? 0 : (value >= 10 ? 1 : 2);
        return `${value.toFixed(digits)} ${units[unitIndex]}`;
    }

    buildProcessingReport(result) {
        if (!result || !result.processing) {
            return null;
        }

        const lines = [];
        const p = result.processing;

        if (p.auto_compress && p.auto_compress.enabled) {
            if (p.auto_compress.compressed) {
                lines.push(
                    `已压缩(${(p.auto_compress.method || 'unknown').toUpperCase()}) ` +
                    `-${p.auto_compress.saved_percent}% ` +
                    `(${p.auto_compress.before_size_text} -> ${p.auto_compress.after_size_text})`
                );
            } else {
                lines.push(`未压缩: ${this.getSkipReasonText(p.auto_compress.skip_reason, 'compress')}`);
            }
        }

        if (p.auto_webp && p.auto_webp.enabled) {
            if (p.auto_webp.created) {
                lines.push(`已生成 WebP: ${p.auto_webp.filename}`);
            } else {
                lines.push(`未转 WebP: ${this.getSkipReasonText(p.auto_webp.skip_reason, 'webp')}`);
            }
        }

        return lines;
    }

    showUploadSuccess(filename, record, result = null) {
        if (record) {
            record.state = 'done';
            record.loaded = record.total;
        }
        // 单文件上传时显示成功通知，批量上传时只在全部完成后统一通知
        if (this.batchTotal <= 1) {
            ImgEt.Utils.showNotification('上传成功', 'success', {
                variant: 'upload',
                title: '上传成功',
                icon: 'fa-check-circle'
            });
        }

        this.updateBatchProgressUI();
    }

    /**
     * 处理上传错误
     */
    handleUploadDuplicate(record, result = null) {
        if (record) {
            record.state = 'duplicate';
            record.loaded = record.total;
            record.error = '已跳过重复';
            record.duplicateFilename = result?.filename || '';
        }
        ImgEt.Utils.showNotification('图片已存在，已跳过', 'warning', {
            variant: 'upload',
            title: '图片已存在，已跳过',
            icon: 'fa-copy'
        });
        this.updateBatchProgressUI();
    }

    handleUploadError(record, message) {
        if (record) {
            record.state = 'failed';
            record.loaded = record.total;
            record.error = message || '上传失败';
        }
        this.updateBatchProgressUI();
    }

    /**
     * 淡出进度条项
     */
    fadeOutProgressItem(progressItem) {
        setTimeout(() => {
            progressItem.style.transition = 'all 0.3s ease';
            progressItem.style.opacity = '0';
            progressItem.style.height = '0';
            progressItem.style.marginBottom = '0';
            
            setTimeout(() => {
                progressItem.remove();
                this.checkUploadComplete();
            }, UploadManager.CONFIG.FADE_DURATION);
        }, 1000);
    }

    /**
     * 检查是否所有文件都已上传完成
     */
    async checkUploadComplete() {
        if (this.batchTotal > 0 && this.batchCompleted >= this.batchTotal && this.activeUploads === 0 && !this.batchNotified) {
            this.batchNotified = true;
            if (this.batchTotal > 1) {
                this.showOrUpdateBatchUploadToast(this.batchSucceeded, this.batchTotal, 'complete');
            }
            const finishedRunId = this.batchRunId;
            if (this.batchProgressVisible && this.progressItem) {
                window.setTimeout(() => this.hideProgressArea(finishedRunId), 2600);
            }

            // 最近上传是“最新 5 张”的排序窗口，上传完成后直接重拉服务端
            // Top 5，避免并发完成顺序和前端插入顺序造成错位。
            if (this.completedUploads.length > 0) {
                try {
                    await GalleryManager.refreshRecentUploads();
                } catch (error) {
                    console.error('刷新最近上传失败:', error);
                }
            }
            this.completedUploads = [];
            this.activeRunIds.clear();
            this.batchProgressVisible = false;
            this.renderUploadQueue();
        }
    }
}

// 初始化上传管理器
document.addEventListener('DOMContentLoaded', () => {
    new UploadManager();
});


/* ===== stats.js ===== */
document.addEventListener('DOMContentLoaded', function () {
    // 非统计页直接跳过，避免全局单包模式下出现无意义控制台告警
    const hasStatsCanvas = document.getElementById('monthlyChart')
        || document.getElementById('yearlyTrendChart')
        || document.getElementById('typeChart')
        || document.getElementById('sizeChart');
    if (!hasStatsCanvas) {
        return;
    }

    if (typeof statsData === 'undefined' || !statsData) {
        console.error('统计页缺少 statsData，图表渲染已跳过');
        return;
    }

    // 通用安全读取（支持 "labels/data" 对象、映射对象、或直接数组）
    function normalizeSeries(input) {
        if (!input) return { labels: [], data: [] };

        // 已经是 { labels: [], data: [] }
        if (Array.isArray(input) && input.length && typeof input[0] !== 'object') {
            // 纯数组 -> 认为是 data，labels 使用索引或占位
            return { labels: input.map((_, i) => String(i + 1)), data: input };
        }

        if (typeof input === 'object') {
            // 形如 { labels: [...], data: [...] }
            if (Array.isArray(input.labels) && Array.isArray(input.data)) {
                return { labels: input.labels.slice(), data: input.data.slice() };
            }

            // 形如 mapping: { "2025-01": 5, "2025-02": 10 }
            const keys = Object.keys(input);
            if (keys.length && (typeof input[keys[0]] === 'number' || typeof input[keys[0]] === 'string')) {
                const labels = keys;
                const data = keys.map(k => Number(input[k]) || 0);
                return { labels, data };
            }

            // 形如 mapping: { "2025-01": {count: X, size: Y}, ... }
            if (keys.length && typeof input[keys[0]] === 'object') {
                const labels = keys;
                const data = keys.map(k => Number(input[k].count || 0));
                return { labels, data };
            }
        }

        return { labels: [], data: [] };
    }

    function safeGet(obj, key) {
        try {
            return key.split('.').reduce((o, k) => (o && k in o) ? o[k] : undefined, obj);
        } catch (e) {
            return undefined;
        }
    }

    function formatPercent(n) {
        return (Math.round(n * 1000) / 10) + '%';
    }

    function cumulativeArray(arr) {
        const out = [];
        let s = 0;
        for (const v of arr) {
            s += Number(v) || 0;
            out.push(s);
        }
        return out;
    }

    function createChart(ctx, config) {
        try {
            return new Chart(ctx, config);
        } catch (e) {
            console.error('Chart create error:', e);
            return null;
        }
    }

    // ===== v2 清爽仪表盘：统一图表主题 =====
    const _css = getComputedStyle(document.documentElement);
    const _isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const THEME = {
        primary: '#0052D9',
        // 类目分类配色 — 和谐的现代调色板,替换 Chart.js 出厂色
        palette: ['#0052D9', '#00A6A6', '#7C5CFC', '#F5A623', '#E5618B', '#34C759', '#2DB7F5', '#9AA0AA'],
        surface: (_css.getPropertyValue('--surface').trim() || (_isDark ? '#161616' : '#ffffff')),
        muted: _isDark ? 'rgba(233,233,234,0.55)' : 'rgba(12,12,12,0.5)',
        grid: _isDark ? 'rgba(255,255,255,0.07)' : 'rgba(15,23,42,0.06)',
    };
    function hexToRgba(hex, a) {
        const r = parseInt(hex.slice(1, 3), 16), g = parseInt(hex.slice(3, 5), 16), b = parseInt(hex.slice(5, 7), 16);
        return `rgba(${r},${g},${b},${a})`;
    }
    // 竖向渐变(面积/柱)——从浓到淡
    function vGradient(ctx, hex, a0 = 0.22, a1 = 0.012, h = 240) {
        const grad = ctx.createLinearGradient(0, 0, 0, h);
        grad.addColorStop(0, hexToRgba(hex, a0));
        grad.addColorStop(1, hexToRgba(hex, a1));
        return grad;
    }
    if (window.Chart) {
        const C = window.Chart;
        C.defaults.font.family = "'Noto Sans SC', -apple-system, BlinkMacSystemFont, sans-serif";
        C.defaults.font.size = 12;
        C.defaults.color = THEME.muted;
        C.defaults.borderColor = THEME.grid;
        if (C.defaults.plugins?.legend?.labels) {
            Object.assign(C.defaults.plugins.legend.labels, { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 14 });
        }
        if (C.defaults.plugins?.tooltip) {
            Object.assign(C.defaults.plugins.tooltip, {
                backgroundColor: _isDark ? 'rgba(0,0,0,0.9)' : 'rgba(15,18,28,0.92)',
                padding: 10, cornerRadius: 8, boxPadding: 6, usePointStyle: true,
                titleColor: '#fff', bodyColor: '#fff', titleFont: { weight: '600', size: 12 },
            });
        }
        Object.assign(C.defaults.elements.bar, { borderRadius: 0, borderSkipped: false });
        Object.assign(C.defaults.elements.line, { tension: 0.35, borderWidth: 2.5 });
        Object.assign(C.defaults.elements.point, { radius: 0, hoverRadius: 5, hitRadius: 12, hoverBorderWidth: 2 });
    }

    // 规范化所有可能的 series 来源（兼容旧/新结构）
    const rawMonthly = safeGet(statsData, 'monthly') ?? safeGet(statsData, 'months') ?? safeGet(statsData, 'by_month') ?? statsData.monthly;
    const rawYearly = safeGet(statsData, 'yearly') ?? safeGet(statsData, 'years') ?? safeGet(statsData, 'by_year') ?? statsData.yearly;
    const rawTypes = safeGet(statsData, 'types') ?? safeGet(statsData, 'by_type') ?? statsData.types;
    const rawSizes = safeGet(statsData, 'sizes') ?? safeGet(statsData, 'by_size_range') ?? statsData.sizes;

    const monthly = normalizeSeries(rawMonthly);
    const yearly = normalizeSeries(rawYearly);
    const types = normalizeSeries(rawTypes);
    const sizes = normalizeSeries(rawSizes);

    // 保证 labels/data 长度一致
    function pad(series) {
        const { labels, data } = series;
        const len = Math.max(labels.length, data.length);
        const L = labels.slice();
        const D = data.slice();
        while (L.length < len) L.push('');
        while (D.length < len) D.push(0);
        return { labels: L, data: D };
    }
    const m = pad(monthly), y = pad(yearly), t = pad(types), s = pad(sizes);

    // 月度图 - 折线 + 累计曲线（双轴）
    try {
        const el = document.getElementById('monthlyChart');
        if (el && m.labels.length) {
            const ctx = el.getContext('2d');
            createChart(ctx, {
                type: 'line',
                data: {
                    labels: m.labels,
                    datasets: [
                        {
                            label: '月度上传（张）',
                            data: m.data,
                            borderColor: THEME.primary,
                            backgroundColor: hexToRgba(THEME.primary, 0.10),
                            fill: true,
                            pointBackgroundColor: THEME.primary,
                            pointHoverBackgroundColor: '#fff',
                            pointHoverBorderColor: THEME.primary,
                            yAxisID: 'y'
                        },
                        {
                            label: '累计上传（张）',
                            data: cumulativeArray(m.data),
                            borderColor: 'rgba(0,166,166,0.85)',
                            backgroundColor: 'transparent',
                            borderDash: [5, 4],
                            borderWidth: 2,
                            fill: false,
                            yAxisID: 'y'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { position: 'top', align: 'end' } },
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                        y: { beginAtZero: true, ticks: { precision: 0, padding: 8 }, grid: { color: THEME.grid, drawTicks: false } }
                    }
                }
            });
        }
    } catch (err) { console.error('monthlyChart error:', err); }

    // 年度趋势 - 柱状图
    try {
        const el = document.getElementById('yearlyTrendChart');
        if (el && y.labels.length) {
            const ctx = el.getContext('2d');
            createChart(ctx, {
                type: 'bar',
                data: {
                    labels: y.labels,
                    datasets: [{
                        label: '年度上传（张）',
                        data: y.data,
                        backgroundColor: THEME.primary,
                        borderWidth: 0,
                        borderRadius: 0,
                        maxBarThickness: 52
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0, padding: 8 }, grid: { color: THEME.grid, drawTicks: false } }
                    }
                }
            });
        }
    } catch (err) { console.error('yearlyTrendChart error:', err); }

    // 类型分布 - 环形图 + 类型占比摘要
    try {
        const el = document.getElementById('typeChart');
        if (el && t.labels.length) {
            const ctx = el.getContext('2d');
            createChart(ctx, {
                type: 'doughnut',
                data: {
                    labels: t.labels,
                    datasets: [{
                        data: t.data,
                        backgroundColor: THEME.palette,
                        borderColor: THEME.surface,
                        borderWidth: 2,
                        hoverOffset: 6,
                        radius: '86%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    layout: {
                        padding: { top: 4, right: 8, bottom: 4, left: 8 }
                    },
                    plugins: {
                        legend: { position: 'right' },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    const value = Number(ctx.raw) || 0;
                                    const total = (t.data || []).reduce((a, b) => a + Number(b || 0), 0) || 1;
                                    return `${ctx.label}: ${value} (${formatPercent(value / total)})`;
                                }
                            }
                        }
                    }
                }
            });

        }
    } catch (err) { console.error('typeChart error:', err); }

    // 大小分布 - 条形图
    try {
        const el = document.getElementById('sizeChart');
        if (el && s.labels.length) {
            const ctx = el.getContext('2d');
            createChart(ctx, {
                type: 'pie',
                data: {
                    labels: s.labels,
                    datasets: [{
                        label: '文件数量',
                        data: s.data,
                        backgroundColor: THEME.palette,
                        borderColor: THEME.surface,
                        borderWidth: 2,
                        hoverOffset: 6,
                        radius: '86%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 4, right: 8, bottom: 4, left: 8 } },
                    plugins: {
                        legend: { position: 'right' },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    const value = Number(ctx.raw) || 0;
                                    const total = (s.data || []).reduce((a, b) => a + Number(b || 0), 0) || 1;
                                    return `${ctx.label}: ${value} (${formatPercent(value / total)})`;
                                }
                            }
                        }
                    }
                }
            });
        }
    } catch (err) { console.error('sizeChart error:', err); }

    // 可选：在控制台输出汇总统计（方便调试/快速检查）
    try {
        const totalImages = (t.data || []).reduce((a, b) => a + Number(b || 0), 0) || (m.data || []).reduce((a,b)=>a+Number(b||0),0) || 0;
        const totalByYear = (y.data || []).reduce((a, b) => a + Number(b || 0), 0);
        console.info('stats summary:', {
            totalImages,
            monthlyPoints: m.labels.length,
            yearlyPoints: y.labels.length,
            types: t.labels.length
        });
    } catch (e) { /* ignore */ }
});


/* ===== docs-highlight.js ===== */
(function () {
  function escapeHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function highlightJson(raw) {
    let s = escapeHtml(raw);

    // key
    s = s.replace(/("(?:\\.|[^"\\])*")(\s*:)/g, '<span class="tok-key">$1</span>$2');
    // string value
    s = s.replace(/(:\s*)("(?:\\.|[^"\\])*")/g, '$1<span class="tok-string">$2</span>');
    // boolean/null
    s = s.replace(/\b(true|false|null)\b/g, '<span class="tok-bool">$1</span>');
    // number
    s = s.replace(/\b-?\d+(?:\.\d+)?(?:e[+\-]?\d+)?\b/gi, '<span class="tok-number">$&</span>');

    return s;
  }

  function highlightBash(raw) {
    let s = escapeHtml(raw);

    // command at line start
    s = s.replace(/(^|\n)(\s*)(curl|php|node|npm|bash|sh)\b/g, '$1$2<span class="tok-cmd">$3</span>');
    // options
    s = s.replace(/(^|\s)(--?[a-zA-Z][\w-]*)/g, '$1<span class="tok-opt">$2</span>');
    // strings
    s = s.replace(/"([^"\\]|\\.)*"/g, '<span class="tok-string">$&</span>');
    // urls
    s = s.replace(/https?:\/\/[^\s"']+/g, '<span class="tok-url">$&</span>');

    return s;
  }

  function run() {
    const blocks = document.querySelectorAll('pre.docs-code[data-lang] code');
    blocks.forEach((codeEl) => {
      const pre = codeEl.closest('pre.docs-code');
      if (!pre) return;
      const lang = (pre.getAttribute('data-lang') || '').toLowerCase();
      const raw = codeEl.textContent || '';

      if (lang === 'json') {
        codeEl.innerHTML = highlightJson(raw);
      } else if (lang === 'bash' || lang === 'shell') {
        codeEl.innerHTML = highlightBash(raw);
      } else {
        codeEl.textContent = raw;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();


/* ===== docs-code-copy.js ===== */
(function () {
  const copyIcon = '<i class="fa-light fa-copy" aria-hidden="true"></i>';
  const checkIcon = '<i class="fa-light fa-check" aria-hidden="true"></i>';

  function notify(message, type) {
    if (window.ImgEt?.Utils?.showNotification) {
      window.ImgEt.Utils.showNotification(message, type);
    }
  }

  async function writeClipboard(text) {
    let clipboardError = null;

    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        return;
      } catch (error) {
        clipboardError = error;
      }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.left = '-9999px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();

    const copied = document.execCommand('copy');
    textarea.remove();

    if (!copied) {
      throw clipboardError || new Error('Copy command failed');
    }
  }

  function getCodeText(block) {
    const code = block.querySelector('pre.docs-code code, pre.docs-code');
    return code ? code.textContent : '';
  }

  function resetButton(btn) {
    btn.classList.remove('is-copied');
    btn.innerHTML = copyIcon;
    btn.title = '复制代码';
    btn.setAttribute('aria-label', '复制代码');
  }

  function markCopied(btn) {
    window.clearTimeout(btn._docsCopyTimer);
    btn.classList.add('is-copied');
    btn.innerHTML = checkIcon;
    btn.title = '复制成功';
    btn.setAttribute('aria-label', '复制成功');
    btn._docsCopyTimer = window.setTimeout(() => resetButton(btn), 1600);
  }

  function normalizeTitle(title) {
    if (title.querySelector('.docs-code-title-text')) return;

    const label = document.createElement('span');
    label.className = 'docs-code-title-text';
    label.textContent = title.textContent.trim();

    title.textContent = '';
    title.appendChild(label);
  }

  function init() {
    document.querySelectorAll('.docs-code-block').forEach((block) => {
      const title = block.querySelector('.docs-code-title');
      if (!title || title.querySelector('.docs-copy-btn')) return;

      normalizeTitle(title);

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'docs-copy-btn';
      btn.innerHTML = copyIcon;
      btn.title = '复制代码';
      btn.setAttribute('aria-label', '复制代码');

      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        event.stopPropagation();

        const text = getCodeText(block);
        if (!text) {
          notify('没有可复制的代码', 'error');
          return;
        }

        try {
          await writeClipboard(text);
          markCopied(btn);
          notify('复制成功', 'success');
        } catch (error) {
          console.error('Docs code copy failed:', error);
          notify('复制失败，请手动复制', 'error');
        }
      });

      title.appendChild(btn);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();


/* ===== view-image.min.js ===== */
/**
 * ViewImage.min.js 2.0.2
 * MIT License - http://www.opensource.org/licenses/mit-license.php
 * https://tokinx.github.io/ViewImage/
 */
 var $jscomp=$jscomp||{};$jscomp.scope={};$jscomp.createTemplateTagFirstArg=function(b){return b.raw=b};$jscomp.createTemplateTagFirstArgWithRaw=function(b,a){b.raw=a;return b};$jscomp.arrayIteratorImpl=function(b){var a=0;return function(){return a<b.length?{done:!1,value:b[a++]}:{done:!0}}};$jscomp.arrayIterator=function(b){return{next:$jscomp.arrayIteratorImpl(b)}};$jscomp.makeIterator=function(b){var a="undefined"!=typeof Symbol&&Symbol.iterator&&b[Symbol.iterator];return a?a.call(b):$jscomp.arrayIterator(b)};
 $jscomp.arrayFromIterator=function(b){for(var a,d=[];!(a=b.next()).done;)d.push(a.value);return d};$jscomp.arrayFromIterable=function(b){return b instanceof Array?b:$jscomp.arrayFromIterator($jscomp.makeIterator(b))};
 (function(){window.ViewImage=new function(){var b=this;this.target="[view-image] img";this.listener=function(a){if(!(a.ctrlKey||a.metaKey||a.shiftKey||a.altKey)){var d=String(b.target.split(",").map(function(g){return g.trim()+":not([no-view])"})),c=a.target.closest(d);if(c){var e=c.closest("[view-image]")||document.body;d=[].concat($jscomp.arrayFromIterable(e.querySelectorAll(d))).map(function(g){return g.href||g.src});b.display(d,c.href||c.src);a.stopPropagation();a.preventDefault()}}};this.init=
 function(a){a&&(b.target=a);["removeEventListener","addEventListener"].forEach(function(d){document[d]("click",b.listener,!1)})};this.display=function(a,d){var c=a.indexOf(d),e=(new DOMParser).parseFromString('\n                <div class="view-image">\n                    <style>.view-image{position:fixed;inset:0;z-index:500;padding:1rem;display:flex;flex-direction:column;animation:view-image-in 300ms;backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}.view-image__out{animation:view-image-out 300ms}@keyframes view-image-in{0%{opacity:0}}@keyframes view-image-out{100%{opacity:0}}.view-image-btn{width:32px;height:32px;display:flex;justify-content:center;align-items:center;cursor:pointer;border-radius:3px;background-color:rgba(255,255,255,0.2)}.view-image-btn:hover{background-color:rgba(255,255,255,0.5)}.view-image-close__full{position:absolute;inset:0;background-color:rgba(48,55,66,0.3);z-index:unset;cursor:zoom-out;margin:0}.view-image-container{height:0;flex:1;display:flex;align-items:center;justify-content:center;}.view-image-lead{display:contents}.view-image-lead img{position:relative;z-index:1;max-width:100%;max-height:100%;object-fit:contain;border-radius:3px}.view-image-lead__in img{animation:view-image-lead-in 300ms}.view-image-lead__out img{animation:view-image-lead-out 300ms forwards}@keyframes view-image-lead-in{0%{opacity:0;transform:translateY(-20px)}}@keyframes view-image-lead-out{100%{opacity:0;transform:translateY(20px)}}[class*=__out] ~ .view-image-loading{display:block}.view-image-loading{position:absolute;inset:50%;width:8rem;height:2rem;color:#aab2bd;overflow:hidden;text-align:center;margin:-1rem -4rem;z-index:1;display:none}.view-image-loading::after{content:"";position:absolute;inset:50% 0;width:100%;height:3px;background:rgba(255,255,255,0.5);transform:translateX(-100%) translateY(-50%);animation:view-image-loading 800ms -100ms ease-in-out infinite}@keyframes view-image-loading{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}.view-image-tools{position:relative;display:flex;justify-content:space-between;align-content:center;color:#fff;max-width:600px;position: absolute; bottom: 5%; left: 1rem; right: 1rem; backdrop-filter: blur(10px);margin:0 auto;padding:10px;border-radius:5px;background:rgba(0,0,0,0.1);margin-bottom:constant(safe-area-inset-bottom);margin-bottom:env(safe-area-inset-bottom);z-index:1}.view-image-tools__count{width:60px;display:flex;align-items:center;justify-content:center}.view-image-tools__flip{display:flex;gap:10px}.view-image-tools [class*=-close]{margin:0 10px}</style>\n                    <div class="view-image-container">\n                        <div class="view-image-lead"></div>\n                        <div class="view-image-loading"></div>\n                        <div class="view-image-close view-image-close__full"></div>\n                    </div>\n                    <div class="view-image-tools">\n                        <div class="view-image-tools__count">\n                            <span><b class="view-image-index">'+
 (c+1)+"</b>/"+a.length+'</span>\n                        </div>\n                        <div class="view-image-tools__flip">\n                            <div class="view-image-btn view-image-tools__flip-prev">\n                                <svg width="20" height="20" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48" fill="white" fill-opacity="0.01"/><path d="M31 36L19 24L31 12" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>\n                            </div>\n                            <div class="view-image-btn view-image-tools__flip-next">\n                                <svg width="20" height="20" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48" fill="white" fill-opacity="0.01"/><path d="M19 12L31 24L19 36" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>\n                            </div>\n                        </div>\n                        <div class="view-image-btn view-image-close">\n                            <svg width="16" height="16" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="48" height="48" fill="white" fill-opacity="0.01"/><path d="M8 8L40 40" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 40L40 8" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>\n                        </div>\n                    </div>\n                </div>\n            ',
 "text/html").body.firstChild,g=function(f){var h={Escape:"close",ArrowLeft:"tools__flip-prev",ArrowRight:"tools__flip-next"};h[f.key]&&e.querySelector(".view-image-"+h[f.key]).click()},l=function(f){var h=new Image,k=e.querySelector(".view-image-lead");k.className="view-image-lead view-image-lead__out";setTimeout(function(){k.innerHTML="";h.onload=function(){setTimeout(function(){k.innerHTML='<img src="'+h.src+'" alt="ViewImage" no-view/>';k.className="view-image-lead view-image-lead__in"},100)};
 h.src=f},300)};document.body.appendChild(e);l(d);window.addEventListener("keydown",g);e.onclick=function(f){f.target.closest(".view-image-close")?(window.removeEventListener("keydown",g),e.onclick=null,e.classList.add("view-image__out"),setTimeout(function(){return e.remove()},290)):f.target.closest(".view-image-tools__flip")&&(c=f.target.closest(".view-image-tools__flip-prev")?0===c?a.length-1:c-1:c===a.length-1?0:c+1,l(a[c]),e.querySelector(".view-image-index").innerHTML=c+1)}}}})();
 
