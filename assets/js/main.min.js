/* LitePic V2.2 JavaScript bundle */

/* ===== script.js ===== */
window.ImgEt = window.ImgEt || {};

/* ===== nav-indicator.js ===== */
(function () {
    function initNavIndicator() {
        const nav = document.querySelector('.main-nav');
        if (!nav) return;

        const indicator = nav.querySelector('.nav-indicator');
        const links = Array.from(nav.querySelectorAll('.nav-link'));
        if (!indicator || links.length === 0) return;

        const activeLink = () => nav.querySelector('.nav-link.active');
        let resizeTimer = null;

        const moveTo = (target) => {
            if (!target || !nav.contains(target)) return;

            const navRect = nav.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();

            nav.style.setProperty('--nav-indicator-x', `${targetRect.left - navRect.left}px`);
            nav.style.setProperty('--nav-indicator-y', `${targetRect.top - navRect.top}px`);
            nav.style.setProperty('--nav-indicator-w', `${targetRect.width}px`);
            nav.style.setProperty('--nav-indicator-h', `${targetRect.height}px`);
            nav.classList.add('is-indicator-ready');
        };

        const restore = () => {
            nav.classList.remove('is-indicator-hovering');
            const active = activeLink();
            if (active) {
                moveTo(active);
            } else {
                nav.classList.remove('is-indicator-ready');
            }
        };

        links.forEach((link) => {
            link.addEventListener('pointerenter', () => {
                nav.classList.add('is-indicator-hovering');
                moveTo(link);
            });
            link.addEventListener('focus', () => {
                nav.classList.add('is-indicator-hovering');
                moveTo(link);
            });
        });

        nav.addEventListener('pointerleave', restore);
        nav.addEventListener('focusout', () => {
            if (!nav.contains(document.activeElement)) {
                restore();
            }
        });

        window.addEventListener('resize', () => {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(restore, 80);
        });

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(restore);
            observer.observe(nav);
            links.forEach((link) => observer.observe(link));
        }

        if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
            document.fonts.ready.then(restore).catch(() => {});
        }

        requestAnimationFrame(restore);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNavIndicator);
    } else {
        initNavIndicator();
    }
})();

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
            const hideTimeout = setTimeout(() => this.hide(item), duration);
            
            // 手动关闭
            const closeBtn = item.querySelector('.notification-close');
            if (closeBtn) {
                closeBtn.onclick = () => {
                    clearTimeout(hideTimeout);
                    this.hide(item);
                };
            }
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
        let notification = document.getElementById('processNotification');
        if (!notification) {
            notification = document.createElement('div');
            notification.id = 'processNotification';
            notification.className = 'process-toast-container';
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
            const dialog = document.createElement('div');
            dialog.className = `confirm-dialog${isDanger ? ' confirm-dialog-danger' : ''}`;
            dialog.innerHTML = `
                <div class="confirm-dialog-content">
                    <div class="confirm-dialog-body">
                        <h3 class="confirm-dialog-title">${safeTitle}</h3>
                        <p class="confirm-dialog-message">${safeMessage}</p>
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
            const closeDialog = () => {
                if (dialog.isClosing) return;
                dialog.isClosing = true;
                dialog.classList.remove('active');
                setTimeout(() => {
                    dialog.removeEventListener('click', clickOutsideHandler);
                    document.removeEventListener('keydown', escHandler);
                    dialog.remove();
                    this.activeDialogs = this.activeDialogs.filter(d => d !== dialog);
                }, 300);
            };

            dialog.closeHandler = closeDialog; // 挂载关闭句柄

            dialog.querySelector('.confirm-dialog-cancel').addEventListener('click', closeDialog);
            dialog.querySelector('.confirm-dialog-submit').addEventListener('click', () => {
                if (typeof onConfirm === 'function' && !dialog.confirmed) {
                    dialog.confirmed = true;
                    onConfirm();
                }
                closeDialog();
            });

            const clickOutsideHandler = e => {
                if (e.target === dialog) closeDialog();
            };
            dialog.addEventListener('click', clickOutsideHandler);

            const escHandler = e => {
                if (e.key === 'Escape') {
                    closeDialog();
                }
            };
            document.addEventListener('keydown', escHandler);

            requestAnimationFrame(() => dialog.classList.add('active'));
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

        const content = `
            <div class="litepic-license-dialog">
                <p class="license-lead">LitePic 是一款开源 PHP 单机版图床程序，适合个人、自托管和轻量团队场景使用。</p>
                <div class="license-meta">
                    <div class="license-meta-row">
                        <span class="license-meta-label"><i class="fa-light fa-code-branch" aria-hidden="true"></i>项目地址</span>
                        <a href="https://github.com/gentpan/LitePic" target="_blank" rel="noopener noreferrer">github.com/gentpan/LitePic</a>
                    </div>
                    <div class="license-meta-row">
                        <span class="license-meta-label"><i class="fa-light fa-user-pen" aria-hidden="true"></i>作者</span>
                        <a href="https://github.com/gentpan" target="_blank" rel="noopener noreferrer">gentpan</a>
                    </div>
                    <div class="license-meta-row">
                        <span class="license-meta-label"><i class="fa-light fa-scale-balanced" aria-hidden="true"></i>开源协议</span>
                        <span>MIT License</span>
                    </div>
                    <div class="license-meta-row">
                        <span class="license-meta-label"><i class="fa-light fa-circle-question" aria-hidden="true"></i>问题反馈</span>
                        <a href="https://github.com/gentpan/LitePic/issues" target="_blank" rel="noopener noreferrer">GitHub Issues</a>
                    </div>
                </div>
                <p class="license-note">二次开发、分发或商用时，请保留版权与协议声明，并以仓库中的 LICENSE 文件为准。</p>
            </div>
        `;

        ImgEt.DialogManager.showCustomDialog('版权说明', content);
    });
}

document.addEventListener('DOMContentLoaded', initLicenseDialog);

// 缩略图右键时临时切换为原图地址，确保“查看图片/在新标签打开图片”走原图
document.addEventListener('contextmenu', (e) => {
    const img = e.target.closest('.img-box img[data-original-url], .img-card img[data-original-url]');
    if (!img) return;

    const originalUrl = img.dataset.originalUrl || '';
    const currentSrc = img.getAttribute('src') || '';
    if (!originalUrl || currentSrc === originalUrl) {
        return;
    }

    img.dataset.thumbUrl = currentSrc;
    img.setAttribute('src', originalUrl);

    window.setTimeout(() => {
        const thumbUrl = img.dataset.thumbUrl || '';
        if (thumbUrl !== '') {
            img.setAttribute('src', thumbUrl);
        }
    }, 5000);
}, true);

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

    finishLogin() {
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

            // 修正：检查 data.status 是否为 'success'，而不是 data.success
            if (data.status === 'success') {
                ImgEt.Utils.showNotification('登录成功', 'success', { variant: 'auth' });
                this.finishLogin();
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
                ImgEt.Utils.showNotification('Passkey 登录成功', 'success', { variant: 'auth' });
                this.finishLogin();
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
            // 1. 获取原始文件名（从元素属性中获取）
            const imgCard = document.querySelector(getImageCardSelector(filename));
            const displayName = imgCard?.querySelector('.img-name')?.textContent?.trim() || filename;

            // 2. 执行删除请求
            await ApiService.request('/api/v1/action', {
                action: 'delete',
                file: filename,
                csrf_token: window.CSRF_TOKEN || ''
            }, { method: 'POST' });
            
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
            
            // 5. 使用原始文件名显示通知
            if (shouldNotifySuccess) {
                ImgEt.Utils.showNotification(`已删除: ${displayName}`, 'success');
            }
            
            // 6. 加载新图片补位
            setTimeout(async () => {
                this.#removeImageElement(filename, suppressLoad);
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
            // 只有在非批量模式才补位（避免重复请求）
            if (!suppressLoad) {
                try {
                    if (document.querySelector('.gallery-shell')) {
                        await GalleryManager.refreshCurrentPage();
                    } else {
                        await this.#loadNewImages(1);
                    }
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
                            if (newCard) GalleryManager.initNewCard(newCard);
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
                            if (newCard) GalleryManager.initNewCard(newCard);
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

    static #showCompressToast(data) {
        const originalSize = data.original_size || '0 B';
        const compressedSize = data.compressed_size || data.size_text || '0 B';
        const savedSize = data.saved_size || '0 B';
        const savedPercent = Number.isFinite(Number(data.saved_percent))
            ? Number(data.saved_percent).toFixed(1).replace(/\.0$/, '')
            : '0';
        ImgEt.Utils.showNotification(
            '压缩完成',
            'success',
            {
                variant: ['process', 'compress'],
                title: '压缩完成',
                detail: `${originalSize} → ${compressedSize}`,
                meta: `节省 ${savedSize} (${savedPercent}%)`,
                icon: 'fa-arrows-minimize',
                duration: 4800
            }
        );
    }

    static #showConvertToast(data, format) {
        const beforeSize = data.before_size_text || data.original_size || '0 B';
        const afterSize = data.after_size_text || data.size_text || '0 B';
        const savedSize = data.saved_size_text || data.saved_size || '0 B';
        const savedPercent = Number.isFinite(Number(data.saved_percent))
            ? Number(data.saved_percent).toFixed(1).replace(/\.0$/, '')
            : '0';
        ImgEt.Utils.showNotification(
            `${format} 转换完成`,
            'success',
            {
                variant: ['process', 'convert'],
                title: `${format} 转换完成`,
                detail: `${beforeSize} → ${afterSize}`,
                meta: `节省 ${savedSize} (${savedPercent}%)`,
                icon: 'fa-wand-magic-sparkles',
                duration: 4800
            }
        );
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
        dialog?.querySelector('.custom-dialog-content')?.classList.add('convert-dialog-content');
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
        if (action === 'webp' || action === 'avif') {
            return { text: '转换', variant: 'convert', icon: 'fa-wand-magic-sparkles' };
        }
        return { text: '删除', variant: 'delete', icon: 'fa-trash' };
    }

    static #upsertDeleteProgressToast(done, total) {
        const host = document.getElementById('notification');
        if (!host) return;
        let item = host.querySelector('.notification-item.batch-delete-progress');
        if (!item) {
            item = document.createElement('div');
            item.className = 'notification-item info batch-delete-progress show';
            item.setAttribute('role', 'status');
            item.innerHTML = `
                <i class="fa-light fa-spinner-third fa-spin text-info" aria-hidden="true"></i>
                <span class="batch-delete-text flex-1 text-sm"></span>
            `;
            host.appendChild(item);
        }
        const text = item.querySelector('.batch-delete-text');
        if (text) text.textContent = `删除 ${done}/${total}`;
    }

    static #removeDeleteProgressToast() {
        const item = document.querySelector('#notification .notification-item.batch-delete-progress');
        if (item) item.remove();
    }

    static #upsertProcessProgressToast(action, done, total) {
        const host = ImgEt.Utils.getProcessNotificationContainer();
        if (!host) return;
        const style = this.#getActionStyle(action);
        const percent = total > 0 ? Math.round((done / total) * 100) : 0;
        let item = host.querySelector('.notification-item.batch-process-progress');
        if (!item) {
            item = document.createElement('div');
            item.className = `notification-item info notification-process notification-batch notification-${style.variant} batch-process-progress show`;
            item.setAttribute('role', 'status');
            item.innerHTML = `
                <i class="fa-light fa-spinner-third fa-spin" aria-hidden="true"></i>
                <div class="notification-copy">
                    <strong>批量${style.text}处理中</strong>
                    <span class="batch-process-detail"></span>
                    <em class="batch-process-meta"></em>
                </div>
            `;
            host.appendChild(item);
        }

        item.classList.toggle('notification-compress', style.variant === 'compress');
        item.classList.toggle('notification-convert', style.variant === 'convert');
        const detail = item.querySelector('.batch-process-detail');
        const meta = item.querySelector('.batch-process-meta');
        if (detail) detail.textContent = `${done}/${total} 张`;
        if (meta) meta.textContent = `${percent}%`;
    }

    static #removeProcessProgressToast() {
        const item = document.querySelector('#processNotification .notification-item.batch-process-progress, #notification .notification-item.batch-process-progress');
        if (item) item.remove();
    }

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
        const confirmOptions = action === 'delete'
            ? { danger: true, confirmText: '删除' }
            : {};

        ImgEt.DialogManager.showConfirmDialog(title, message, () => {
            this.process(action, selectedFiles);
        }, confirmOptions);
    }

    static async process(action, files) {
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

        if (action !== 'delete') {
            this.#upsertProcessProgressToast(action, 0, total);
        }

        for (const [index, filename] of files.entries()) {
            const imgCard = document.querySelector(getImageCardSelector(filename));
            if (!imgCard) {
                results.fail++;
                continue;
            }

            if (action === 'delete') {
                this.#upsertDeleteProgressToast(index + 1, total);
            } else {
                this.#upsertProcessProgressToast(action, index + 1, total);
            }

            try {
                switch (action) {
                    case 'compress':
                        await ImageProcessor.processSingleCompress(filename, imgCard, { notify: false, notifyError: false });
                        break;
                    case 'webp':
                        await ImageProcessor.processSingleWebP(filename, imgCard, { notify: false, notifyError: false, dialog: false });
                        break;
                    case 'avif':
                        await ImageProcessor.processSingleAvif(filename, imgCard, { notify: false, notifyError: false, dialog: false });
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

        if (action === 'delete') {
            this.#removeDeleteProgressToast();
        } else {
            this.#removeProcessProgressToast();
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
        } else if (btn.classList.contains('delete-btn')) {
            DeleteManager.handleDelete(filename);
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
            const iconClass = { compress: 'fa-compress', webp: 'fa-image', avif: 'fa-image', delete: 'fa-trash' }[action] || 'fa-check';
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


/* ===== upload.js ===== */
/**
 * 上传管理器类
 */
class UploadManager {
    // 配置常量
    static CONFIG = {
        MAX_SIZE: 20 * 1024 * 1024, // 默认最大文件大小（会被后端传值覆盖）
        TIMEOUT_SMALL: 30000, // <= 2MB: 30秒
        TIMEOUT_MEDIUM: 90000, // <= 10MB: 90秒
        TIMEOUT_LARGE: 180000, // > 10MB: 180秒
        TIMEOUT_SMALL_THRESHOLD: 2 * 1024 * 1024,
        TIMEOUT_MEDIUM_THRESHOLD: 10 * 1024 * 1024,
        MAX_CONCURRENT: 3, // 最大并发上传数
        FADE_DURATION: 300, // 动画过渡时间
    };

    constructor() {
        this.initElements();
        this.batchTotal = 0;
        this.batchCompleted = 0;
        this.batchSucceeded = 0;
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
        this.completedUploads = [];
        this.headerUploadButton = document.querySelector('.nav-cta-btn[href="/upload"]');
        this.headerUploadIcon = this.headerUploadButton?.querySelector('i') || null;
        this.headerUploadLabel = this.headerUploadButton?.querySelector('span') || null;
        this.headerUploadDefaultIcon = this.headerUploadIcon ? this.headerUploadIcon.className : '';
        this.headerUploadDefaultText = this.headerUploadLabel ? this.headerUploadLabel.textContent : '上传';
        this.lastNavigationNoticeAt = 0;
        this.maxSize = UploadManager.CONFIG.MAX_SIZE;
        this.autoCompressEnabled = false;
        this.autoWebpEnabled = false;
        this.autoAvifEnabled = false;
        this.allowedExtensions = new Set();
        if (this.elements.imageInput && this.elements.imageInput.dataset.maxSize) {
            const parsed = parseInt(this.elements.imageInput.dataset.maxSize, 10);
            if (Number.isFinite(parsed) && parsed > 0) {
                this.maxSize = parsed;
            }
        }
        if (this.elements.imageInput) {
            this.autoCompressEnabled = this.elements.imageInput.dataset.autoCompress === '1';
            this.autoWebpEnabled = this.elements.imageInput.dataset.autoWebp === '1';
            this.autoAvifEnabled = this.elements.imageInput.dataset.autoAvif === '1';
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
        return {
            compress: !!compressToggle?.checked,
            convert: !!convertToggle?.checked,
            format: String(controls?.dataset.convertFormat || 'webp').toLowerCase()
        };
    }

    async saveUploadProcessingSettings(changed, activeToggle = null) {
        const controls = this.elements.processingControls;
        if (!controls) return;

        const before = {
            compress: this.autoCompressEnabled,
            convert: this.autoWebpEnabled || this.autoAvifEnabled,
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
            formData.append('convert_preferred_format', state.format === 'avif' ? 'avif' : 'webp');

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
        const format = String(settings.convert_preferred_format || controls.dataset.convertFormat || 'webp').toLowerCase();

        this.autoCompressEnabled = autoCompress;
        this.autoWebpEnabled = autoConvert && format === 'webp';
        this.autoAvifEnabled = autoConvert && format === 'avif';
        imageInput.dataset.autoCompress = this.autoCompressEnabled ? '1' : '0';
        imageInput.dataset.autoWebp = this.autoWebpEnabled ? '1' : '0';
        imageInput.dataset.autoAvif = this.autoAvifEnabled ? '1' : '0';
        controls.dataset.convertFormat = format === 'avif' ? 'avif' : 'webp';

        const compressToggle = controls.querySelector('[data-upload-setting-toggle="compress"]');
        const convertToggle = controls.querySelector('[data-upload-setting-toggle="convert"]');
        if (compressToggle) {
            compressToggle.checked = this.autoCompressEnabled;
            compressToggle.closest('.upload-setting-toggle')?.classList.toggle('is-active', this.autoCompressEnabled);
        }
        if (convertToggle) {
            convertToggle.checked = this.autoWebpEnabled || this.autoAvifEnabled;
            convertToggle.closest('.upload-setting-toggle')?.classList.toggle('is-active', this.autoWebpEnabled || this.autoAvifEnabled);
        }

        const compressValue = controls.querySelector('[data-upload-compress-value]');
        const convertValue = controls.querySelector('[data-upload-convert-value]');
        if (compressValue) {
            compressValue.textContent = this.autoCompressEnabled ? (settings.compression_label || '开启') : '关闭';
        }
        if (convertValue) {
            convertValue.textContent = (this.autoWebpEnabled || this.autoAvifEnabled)
                ? (settings.conversion_label || (format === 'avif' ? 'AVIF' : 'WebP'))
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
    handleFiles(files) {
        if (!this.validateEnvironment() || !this.validateFiles(files)) return;

        const validFiles = this.filterValidFiles(files);
        if (validFiles.length === 0) return;

        validFiles.forEach(file => this.createUploadRecord(file));
        if (this.elements.imageInput) {
            this.elements.imageInput.value = '';
        }
        this.renderUploadQueue();
        ImgEt.Utils.showNotification(`已加入队列 ${validFiles.length} 个文件`, 'success');
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
            previewUrl: file.type && file.type.startsWith('image/') ? URL.createObjectURL(file) : '',
            loaded: 0,
            total: Number.isFinite(file.size) && file.size > 0 ? file.size : 1,
            state: 'queued',
            error: ''
        };
        this.uploadRecords.set(id, record);
        return record;
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
            const thumb = record.previewUrl
                ? `<img src="${record.previewUrl}" alt="">`
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

        const settle = (success = false) => {
            this.activeUploads = Math.max(0, this.activeUploads - 1);
            this.batchCompleted += 1;
            if (success) {
                this.batchSucceeded += 1;
                record.state = 'done';
            } else {
                this.batchFailed += 1;
                record.state = 'failed';
            }
            record.loaded = record.total;
            this.renderUploadQueue();
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
        while (this.activeUploads < UploadManager.CONFIG.MAX_CONCURRENT && this.uploadQueue.length > 0) {
            const record = this.uploadQueue.shift();
            this.uploadSingleFile(record);
        }
    }

    /**
     * 绑定上传事件
     */
    bindUploadEvents(xhr, file, record, settle) {
        let settled = false;
        const settleOnce = (success = false) => {
            if (settled) return;
            settled = true;
            settle(success);
        };

        // 错误处理
        xhr.addEventListener('timeout', () => {
            this.handleUploadError(record, `上传超时（${Math.round(xhr.timeout / 1000)} 秒）`);
            settleOnce(false);
        });
        xhr.addEventListener('error', () => {
            this.handleUploadError(record, '网络错误');
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
        this.updateBatchProgressUI();
    }

    showServerProcessing(record) {
        if (!record) return;
        record.state = 'processing';
        record.loaded = record.total;
        const tasks = [];
        if (this.autoCompressEnabled) tasks.push('开始压缩');
        if (this.autoWebpEnabled) tasks.push('开始转换 WebP');
        if (this.autoAvifEnabled) tasks.push('开始转换 AVIF');
        record.processingText = tasks.length > 0
            ? `服务器处理中：${tasks.join('，')}`
            : '服务器处理中';
        this.updateBatchProgressUI();
    }

    getRecordProgress(record) {
        if (!record) return 0;
        if (record.state === 'done' || record.state === 'failed') return 1;
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
        this.renderUploadQueue();
        if (progressPercent) {
            progressPercent.textContent = `${percent}%`;
        }
        if (progressCount) {
            progressCount.textContent = `${this.batchCompleted}/${this.batchTotal}`;
        }

        const activeNames = Array.from(this.uploadRecords.values())
            .filter(record => this.activeRunIds.has(record.id))
            .filter(record => record.state === 'uploading' || record.state === 'processing')
            .slice(0, UploadManager.CONFIG.MAX_CONCURRENT)
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
                this.headerUploadIcon.className = 'fa-light fa-spinner fa-spin';
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
            this.headerUploadIcon.className = this.batchFailed > 0
                ? 'fa-light fa-triangle-exclamation'
                : 'fa-light fa-check';
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
                throw new Error(response?.message || `服务器错误 (${xhr.status})`);
            }

            if (!response.results) {
                throw new Error(response.message || '上传失败');
            }

            const result = response.results[0] || null;
            if (!result || result.status !== 'success') {
                throw new Error((result && result.message) || response.message || '上传失败');
            }

            // 修改这里：使用原始文件名显示成功消息
            this.showUploadSuccess(file.name, record, result);
            this.completedUploads.push(result);
            return true;

        } catch (err) {
            console.error('Upload error:', err);
            this.handleUploadError(record, err.message);
            return false;
        }
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
        const reportLines = this.buildProcessingReport(result);
        const hasReport = Array.isArray(reportLines) && reportLines.length > 0;
        const hasSkip = hasReport && reportLines.some(line => line.includes('未压缩') || line.includes('未转 WebP'));

        // 单文件上传时显示成功通知，批量上传时只在全部完成后统一通知
        if (this.batchTotal <= 1) {
            const detail = hasReport ? `上传成功：${reportLines.join('；')}` : '上传成功';
            ImgEt.Utils.showNotification('', hasSkip ? 'warning' : 'success', {
                variant: 'upload',
                title: filename,
                detail,
                icon: hasSkip ? 'fa-triangle-exclamation' : 'fa-check-circle'
            });
        }

        this.updateBatchProgressUI();
    }

    /**
     * 处理上传错误
     */
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
                const message = this.batchFailed > 0
                    ? `上传完成：成功 ${this.batchSucceeded} 个，失败 ${this.batchFailed} 个`
                    : `所有文件上传完成，共 ${this.batchSucceeded} 个`;
                ImgEt.Utils.showNotification(message, this.batchFailed > 0 ? 'warning' : 'success');
            }
            const finishedRunId = this.batchRunId;
            if (this.batchProgressVisible && this.progressItem) {
                window.setTimeout(() => this.hideProgressArea(finishedRunId), 2600);
            }

            // 动态插入新上传的卡片（替代刷新页面），最多保留 5 个
            const uploadGrid = document.querySelector('.upload-grid');
            if (uploadGrid && this.completedUploads.length > 0) {
                for (const result of this.completedUploads) {
                    try {
                        const cardHtml = await ApiService.getCardTemplate(result, 'recent');
                        uploadGrid.insertAdjacentHTML('afterbegin', cardHtml);
                        const newCard = uploadGrid.firstElementChild;
                        if (newCard) {
                            GalleryManager.initNewCard(newCard);
                        }
                    } catch (err) {
                        console.error('插入新卡片失败:', err);
                    }
                }
                // 超出 5 个时移除末尾多余的卡片
                while (uploadGrid.children.length > 5) {
                    const last = uploadGrid.lastElementChild;
                    if (last) last.remove();
                }
                GalleryManager.updateImageCount();
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
                            borderColor: 'rgb(54,162,235)',
                            backgroundColor: 'rgba(54,162,235,0.08)',
                            tension: 0.12,
                            yAxisID: 'y'
                        },
                        {
                            label: '累计上传（张）',
                            data: cumulativeArray(m.data),
                            borderColor: 'rgb(75,192,192)',
                            backgroundColor: 'rgba(75,192,192,0.06)',
                            tension: 0.12,
                            borderDash: [6, 4],
                            yAxisID: 'y'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { position: 'top' } },
                    interaction: { mode: 'index', intersect: false },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
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
                        backgroundColor: 'rgba(76,175,80,0.6)',
                        borderColor: 'rgb(76,175,80)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
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
                        backgroundColor: [
                            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#8DD3C7', '#FB8072', '#A8A8A8'
                        ],
                        radius: '76%'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '56%',
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

            // 类型占比摘要（插入到图表容器底部）
            const parent = el.closest('.chart-container');
            if (parent) {
                // 清理旧摘要
                const old = parent.querySelector('.type-summary');
                if (old) old.remove();

                const total = (t.data || []).reduce((a, b) => a + Number(b || 0), 0) || 1;
                const pairs = t.labels.map((lab, i) => ({ label: lab, value: Number(t.data[i] || 0) }));
                pairs.sort((a, b) => b.value - a.value);

                const top = pairs.slice(0, 6);
                const div = document.createElement('div');
                div.className = 'type-summary';
                div.style.marginTop = '8px';
                div.style.fontSize = '0.9rem';
                div.innerHTML = top.map(p => {
                    return `<div class="flex justify-between py-0.5">
                        <span class="text-gray">${p.label}</span>
                        <span class="font-semibold">${p.value} (${formatPercent(p.value / total)})</span>
                    </div>`;
                }).join('') + `<div class="border-t border-border pt-1.5 mt-1.5 text-gray text-sm">总计: ${pairs.reduce((a,b)=>a+b.value,0)} 张</div>`;

                parent.appendChild(div);
            }
        }
    } catch (err) { console.error('typeChart error:', err); }

    // 大小分布 - 条形图
    try {
        const el = document.getElementById('sizeChart');
        if (el && s.labels.length) {
            const ctx = el.getContext('2d');
            createChart(ctx, {
                type: 'bar',
                data: {
                    labels: s.labels,
                    datasets: [{
                        label: '文件数量',
                        data: s.data,
                        backgroundColor: 'rgba(54,162,235,0.5)',
                        borderColor: 'rgb(54,162,235)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
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
 
