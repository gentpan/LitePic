/* LitePic V2 JavaScript bundle - generated 2026-02-27 11:33:17 */

/* ===== script.js ===== */
window.ImgEt = window.ImgEt || {};

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
     */
    showNotification(message, type = 'info') {
        try {
            const notification = document.getElementById('notification');
            if (!notification) return;

            const item = this.createNotificationItem(message, type);
            notification.appendChild(item);
            
            // 添加显示动画
            requestAnimationFrame(() => item.classList.add('show'));
            
            // 自动关闭
            const hideTimeout = setTimeout(() => this.hide(item), 3000);
            
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
     * 创建通知项
     * @private
     */
    createNotificationItem(message, type) {
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        const item = document.createElement('div');
        item.className = `notification-item ${type}`;
        item.setAttribute('role', 'alert');
        
        item.innerHTML = `
            <i class="fa-light ${icons[type] || icons.info}" aria-hidden="true"></i>
            <span>${message}</span>
            <button class="notification-close" aria-label="关闭通知">
                <i class="fa-light fa-times"></i>
            </button>
        `;

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
        const content = `
            <div class="copy-options">
                <div class="copy-option">
                    <div class="copy-option-header">URL</div>
                    <div class="copy-option-content">
                        <input type="text" value="${url}" readonly>
                        <button class="copy-btn" data-copy="${url}">
                            <i class="fa-light fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="copy-option">
                    <div class="copy-option-header">HTML</div>
                    <div class="copy-option-content">
                        <input type="text" value="<img src=&quot;${url}&quot; alt=&quot;${filename}&quot;>" readonly>
                        <button class="copy-btn" data-copy="<img src=&quot;${url}&quot; alt=&quot;${filename}&quot;>">
                            <i class="fa-light fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="copy-option">
                    <div class="copy-option-header">Markdown</div>
                    <div class="copy-option-content">
                        <input type="text" value="![${filename}](${url})" readonly>
                        <button class="copy-btn" data-copy="![${filename}](${url})">
                            <i class="fa-light fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="copy-option">
                    <div class="copy-option-header">BBCode</div>
                    <div class="copy-option-content">
                        <input type="text" value="[img]${url}[/img]" readonly>
                        <button class="copy-btn" data-copy="[img]${url}[/img]">
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
            const dialog = document.createElement('div');
            dialog.className = 'custom-dialog';
            
            dialog.innerHTML = `
                <div class="custom-dialog-content">
                    <div class="dialog-header">
                        <h3>${title}</h3>
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
            
            // 显示动画
            requestAnimationFrame(() => dialog.classList.add('active'));

            // 关闭处理
            const close = () => {
                dialog.classList.remove('active');
                setTimeout(() => dialog.remove(), 300);
            };

            dialog.querySelector('.dialog-close').addEventListener('click', close);
            dialog.addEventListener('click', e => {
                if (e.target === dialog) close();
            });
            
            // ESC 键关闭
            const escHandler = e => {
                if (e.key === 'Escape') {
                    close();
                    document.removeEventListener('keydown', escHandler);
                }
            };
            document.addEventListener('keydown', escHandler);
        },

        showConfirmDialog(title, message, onConfirm) {
            const dialog = document.createElement('div');
            dialog.className = 'confirm-dialog';
            dialog.innerHTML = `
                <div class="confirm-dialog-content">
                    <div class="dialog-header">
                        <h3>
                            <i class="fa-light fa-question-circle"></i>
                            ${title}
                        </h3>
                        <button type="button" class="dialog-close">
                            <i class="fa-light fa-times"></i>
                        </button>
                    </div>
                    <div class="dialog-body">
                        <p>${message}</p>
                    </div>
                    <div class="dialog-footer">
                        <button type="button" class="btn btn-cancel">
                            <i class="fa-light fa-times"></i>
                            取消
                        </button>
                        <button type="button" class="btn btn-danger">
                            <i class="fa-light fa-check"></i>
                            确认
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(dialog);
            this.activeDialogs.push(dialog);

            // 改进关闭处理
            const closeDialog = () => {
                dialog.classList.remove('active');
                setTimeout(() => {
                    dialog.remove();
                    this.activeDialogs = this.activeDialogs.filter(d => d !== dialog);
                    document.removeEventListener('keydown', escHandler);
                }, 300);
            };
            
            dialog.closeHandler = closeDialog; // 挂载关闭句柄

            dialog.querySelector('.dialog-close').addEventListener('click', closeDialog);
            dialog.querySelector('.btn-cancel').addEventListener('click', closeDialog);
            dialog.querySelector('.btn-danger').addEventListener('click', () => {
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
                closeDialog();
            });

            dialog.addEventListener('click', e => {
                if (e.target === dialog) closeDialog();
            });

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

        // 添加全局错误处理
        window.onerror = (msg, url, line, col, error) => {
            console.error('Global error:', error);
            ImgEt.Utils.showNotification('系统错误，请刷新页面重试', 'error');
        };
    } catch (error) {
        console.error('Script initialization failed:', error);
    }
});

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

    function applyTheme(mode) {
        let applied = mode;
        if (mode === 'system') {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            applied = prefersDark ? 'dark' : 'light';
        }
        
        // 设置主题
        if (applied === 'dark') {
            html.setAttribute('data-theme', 'dark');
        } else {
            html.removeAttribute('data-theme');
        }
        
        localStorage.setItem(KEY, mode);
        syncThemeButtons(mode);
    }

    function syncThemeButtons(mode) {
        if (!themeButtons.length) return;
        themeButtons.forEach((btn) => {
            const isActive = btn.getAttribute('data-theme-mode') === mode;
            btn.setAttribute('aria-checked', isActive ? 'true' : 'false');
            btn.classList.toggle('is-active', isActive);
        });
    }

    function initTheme() {
        const saved = localStorage.getItem(KEY) || 'system';
        applyTheme(saved);

        if (themeButtons.length) {
            themeButtons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const mode = btn.getAttribute('data-theme-mode') || 'system';
                    applyTheme(mode);
                });
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

// 上传页面的图片卡片交互
document.addEventListener('DOMContentLoaded', () => {
    const uploadGrid = document.querySelector('.upload-grid');
    if (!uploadGrid) return;

    uploadGrid.addEventListener('click', async e => {
        const btn = e.target.closest('.action-btn');
        if (!btn) return;
        
        const imgBox = btn.closest('.img-box');
        if (!imgBox) return;

        const imgEl = imgBox.querySelector('img');
        const imgUrl = imgBox.dataset.url || imgEl?.dataset?.originalUrl || imgEl?.src || '';
        const filename = imgBox.dataset.filename;

        // 根据按钮类型执行不同操作
        if (btn.classList.contains('copy-btn')) {
            // 直接复制URL
            try {
                await navigator.clipboard.writeText(imgUrl);
                ImgEt.Utils.showNotification('图片链接已复制', 'success');
            } catch (error) {
                ImgEt.Utils.showNotification('复制失败', 'error'); 
            }
        } else if (btn.classList.contains('delete-btn')) {
            ImgEt.DialogManager.showConfirmDialog(
                '删除确认',
                `确定要删除图片 ${filename} 吗？`,
                async () => {
                    try {
                        // 1. 执行删除请求
                        await ApiService.request('/action.php', {
                            action: 'delete',
                            file: filename
                        });
                        
                        // 2. 更新总数显示
                        const totalCountEl = document.querySelector('.total-count');
                        if (totalCountEl) {
                            const currentTotal = parseInt(totalCountEl.dataset.total || '0', 10);
                            const newTotal = Math.max(0, currentTotal - 1);
                            totalCountEl.dataset.total = String(newTotal);
                            totalCountEl.textContent = `(共 ${newTotal} 张图片)`;
                        }

                        // 3. 移除当前卡片
                        imgBox.classList.add('deleting');
                        
                        // 4. 加载新图片补位
                        setTimeout(async () => {
                            imgBox.remove();

                            // 补位逻辑
                            const currentCards = uploadGrid.querySelectorAll('.img-box').length;
                            if (currentCards < 5) {
                                try {
                                    const response = await ApiService.request('/action.php', {
                                        action: 'get_next_image',
                                        current_count: currentCards,
                                        count: 1
                                    });

                                    if (response?.status === 'success' && response.images?.[0]) {
                                        const newImage = response.images[0];
                                        const newCard = `
                                            <div class="img-box" 
                                                data-filename="${newImage.filename}"
                                                data-date="${newImage.time}"
                                                data-size="${newImage.size}"
                                                data-url="${newImage.url}">
                                                <img src="${newImage.thumb_url || newImage.url}" 
                                                     data-original-url="${newImage.url}"
                                                     alt="${newImage.filename}"
                                                     loading="lazy">
                                                <div class="img-overlay">
                                                    <button class="action-btn copy-btn" 
                                                            title="复制图片链接"
                                                            type="button">
                                                        <i class="fa-light fa-copy"></i>
                                                    </button>
                                                    <button class="action-btn delete-btn" 
                                                            type="button"
                                                            title="删除图片">
                                                        <i class="fa-light fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        `;
                                        uploadGrid.insertAdjacentHTML('beforeend', newCard);
                                    }
                                } catch (error) {
                                    console.error('Failed to load replacement image:', error);
                                }
                            }

                            ImgEt.Utils.showNotification('删除成功', 'success');
                        }, 300);

                    } catch (error) {
                        ImgEt.Utils.showNotification(
                            error.message || '删除失败',
                            'error'
                        );
                    }
                }
            );
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

        // 绑定登录按钮（可有多个）
        loginButtons.forEach((button) => {
            button.dataset.authBound = '1';
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleLoginPanel();
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
                const clickedInsidePanel = panel.contains(e.target);
                const clickedButton = primaryLoginButton.contains(e.target);
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

    toggleLoginPanel() {
        const panel = document.getElementById('loginPanel');
        if (!panel) return;
        if (panel.classList.contains('active')) {
            this.hideLoginPanel();
        } else {
            this.showLoginPanel();
        }
    },

    // 显示登录下拉
    showLoginPanel() {
        const panel = document.getElementById('loginPanel');
        const input = document.getElementById('apiKey');
        const button = this.primaryLoginButton || document.querySelector('.login-btn');
        
        if (!panel || !input) return;
        
        panel.classList.add('active');
        panel.setAttribute('aria-hidden', 'false');
        if (button) button.setAttribute('aria-expanded', 'true');
        input.value = ''; // 清空输入
        setTimeout(() => input.focus(), 100); // 延迟聚焦
    },

    // 隐藏登录下拉
    hideLoginPanel() {
        const panel = document.getElementById('loginPanel');
        const button = this.primaryLoginButton || document.querySelector('.login-btn');
        if (!panel) return;
        
        panel.classList.remove('active');
        panel.setAttribute('aria-hidden', 'true');
        if (button) button.setAttribute('aria-expanded', 'false');
    },

    // 登录处理
    async login() {
        const input = document.getElementById('apiKey');
        const submit = document.querySelector('.login-submit');
        
        if (!input || !submit) {
            ImgEt.Utils.showNotification('系统错误', 'error');
            return;
        }

        const apiKey = input.value.trim();
        
        if (!apiKey) {
            ImgEt.Utils.showNotification('请输入API Key', 'error');
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
                ImgEt.Utils.showNotification('登录成功', 'success');
                this.hideLoginPanel();
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                throw new Error(data.message || 'API Key 无效');
            }
        } catch (error) {
            console.error('Login error:', error);
            ImgEt.Utils.showNotification(error.message || '登录失败，请重试', 'error');
            input.focus();
        } finally {
            // 恢复提交按钮
            submit.disabled = false;
            submit.innerHTML = '<i class="fa-light fa-right-to-bracket"></i> 登录';
        }
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
                ImgEt.Utils.showNotification('已退出登录', 'success');
                
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
            ImgEt.Utils.showNotification(error.message || '退出失败', 'error');
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
                window.ImgEt?.Utils?.showNotification?.(data?.message || '退出失败', 'error');
            }
        } catch (_) {
            window.ImgEt?.Utils?.showNotification?.('退出失败', 'error');
        }
        return;
    }

    const loginBtn = e.target.closest('.login-btn');
    if (loginBtn && loginBtn.dataset.authBound !== '1') {
        e.preventDefault();
        const panel = document.getElementById('loginPanel');
        if (panel) {
            panel.classList.toggle('active');
            panel.setAttribute('aria-hidden', panel.classList.contains('active') ? 'false' : 'true');
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
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null) url.searchParams.append(k, String(v));
        });

        const defaultOptions = {
            method: 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-cache'
        };

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

    static async getCardTemplate(data) {
        const body = new URLSearchParams({
            img: data.filename,
            info: JSON.stringify({
                filename: data.filename,
                original_name: data.original_name || data.filename,
                size: data.size,
                dimensions: data.dimensions || '',
                format: data.format || '',
                time: Date.now(),
                url: data.url
            })
        });

        // 使用 action.php 渲染端点输出卡片 HTML
        const res = await fetch('/action.php?action=render_card', {
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
            ? `确定要删除选中的 ${target.length} 张图片吗？`
            : `确定要删除图片 ${target} 吗？`;

        ImgEt.DialogManager.showConfirmDialog('删除确认', message, async () => {
            if (isBatch) {
                await BatchProcessor.process('delete', target);
            } else {
                await this.processSingle(target);
            }
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
            await ApiService.request('/action.php', {
                action: 'delete',
                file: filename
            });
            
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
                try { await this.#loadNewImages(1); } catch (e) { /* already handled inside */ }
            }
        }, this.#updateDelay);
    }

    static async #loadNewImages(count = 1) {
        try {
            const currentCount = document.querySelectorAll('.img-card').length;
            const response = await ApiService.request('/action.php', {
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
                const gallery = document.querySelector('.gallery');
                if (!gallery) return;

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
    static async processSingleCompress(filename, imgCard) {
        if (this.isProcessing()) {
            ImgEt.Utils.showNotification('有操作正在进行中', 'warning');
            return;
        }
        this.startProcessing();
        const btn = imgCard?.querySelector?.('.compress-btn') || null;
        GalleryManager.setButtonLoadingState(btn, true, 'compress');

        try {
            const data = await ApiService.request('/action.php', { action: 'compress', file: filename });
            const sizeText = imgCard.querySelector('.img-size');
            if (sizeText && data?.size_text) sizeText.textContent = data.size_text;
            this.#showCompressResult(data);
            ImgEt.Utils.showNotification('压缩成功', 'success');
        } catch (error) {
            ImgEt.Utils.showNotification(`压缩失败: ${error.message}`, 'error');
            throw error;
        } finally {
            GalleryManager.setButtonLoadingState(btn, false, 'compress');
            this.endProcessing();
        }
    }

    static async processSingleWebP(filename, imgCard) {
        if (this.isProcessing()) {
            ImgEt.Utils.showNotification('有操作正在进行中', 'warning');
            return;
        }
        this.startProcessing();
        const btn = imgCard?.querySelector?.('.webp-btn') || null;
        GalleryManager.setButtonLoadingState(btn, true, 'webp');

        try {
            const data = await ApiService.request('/action.php', { action: 'webp', file: filename });
            this.#showWebPResult(data);
            await this.#addWebPCard(data, imgCard);
            ImgEt.Utils.showNotification('转换成功', 'success');
        } catch (error) {
            ImgEt.Utils.showNotification(`转换失败: ${error.message}`, 'error');
            throw error;
        } finally {
            GalleryManager.setButtonLoadingState(btn, false, 'webp');
            this.endProcessing();
        }
    }

    static #showCompressResult(data) {
        const content = `
            <div class="compress-result">
                <div class="result-item"><span>原始大小:</span><span>${data.original_size}</span></div>
                <div class="result-item"><span>压缩后:</span><span>${data.compressed_size}</span></div>
                <div class="result-item success"><span>节省空间:</span><span>${data.saved_size} (${data.saved_percent}%)</span></div>
            </div>`;
        ImgEt.DialogManager.showCustomDialog('压缩完成', content);
    }

    static #showWebPResult(data) {
        const content = `
            <div class="webp-result">
                <div class="result-item"><span>转换成功:</span><span>${data.filename}</span></div>
                <div class="result-item"><span>文件大小:</span><span>${data.size_text}</span></div>
                <div class="result-preview"><img src="${data.url}" alt="${data.filename}" loading="lazy"></div>
            </div>`;
        ImgEt.DialogManager.showCustomDialog('转换完成', content);
    }

    static async #addWebPCard(data, imgCard) {
        try {
            const newCardHtml = await ApiService.getCardTemplate(data);
            // 在原卡片后插入新的 WebP 卡片
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
            console.error('添加 WebP 卡片失败:', err);
        }
    }
}

/**
 * BatchProcessor - 批量处理
 */
class BatchProcessor extends BaseProcessor {
    static #upsertDeleteProgressToast(done, total) {
        const host = document.getElementById('notification');
        if (!host) return;
        let item = host.querySelector('.notification-item.batch-delete-progress');
        if (!item) {
            item = document.createElement('div');
            item.className = 'notification-item info batch-delete-progress show';
            item.setAttribute('role', 'status');
            item.innerHTML = `
                <i class="fa-light fa-spinner-third fa-spin" aria-hidden="true"></i>
                <span class="batch-delete-text"></span>
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

        const actionText = { compress: '压缩', webp: '转换', delete: '删除' }[action] || action;
        const message = `确定要批量${actionText}选中的 ${selectedFiles.length} 张图片吗？`;

        ImgEt.DialogManager.showConfirmDialog(`批量${actionText}确认`, message, () => {
            this.process(action, selectedFiles);
        });
    }

    static async process(action, files) {
        this.startProcessing();
        const results = { success: 0, fail: 0 };
        const total = files.length;
        const actionText = { compress: '压缩', webp: '转换', delete: '删除' }[action] || action;

        for (const [index, filename] of files.entries()) {
            const imgCard = document.querySelector(getImageCardSelector(filename));
            if (!imgCard) {
                results.fail++;
                continue;
            }

            if (action === 'delete') {
                this.#upsertDeleteProgressToast(index + 1, total);
            } else {
                const progress = Math.round(((index + 1) / total) * 100);
                ImgEt.Utils.showNotification(`处理中 (${progress}%): ${actionText} ${filename}`, 'info');
            }

            try {
                switch (action) {
                    case 'compress':
                        await ImageProcessor.processSingleCompress(filename, imgCard);
                        break;
                    case 'webp':
                        await ImageProcessor.processSingleWebP(filename, imgCard);
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
        }

        ImgEt.Utils.showNotification(
            `批量${actionText}完成: ${results.success}张成功${results.fail ? `, ${results.fail}张失败` : ''}`,
            results.fail ? 'warning' : 'success'
        );

        // 重置选择状态
        const selectAllCheckbox = document.querySelector('#selectAll');
        if (selectAllCheckbox) selectAllCheckbox.checked = false;
        document.querySelectorAll('.select-img:checked').forEach(cb => cb.checked = false);
        GalleryManager.updateSelectedCount();
        GalleryManager.updateImageCount();

        // 批量删除后补位加载（带保护）
        if (action === 'delete' && results.success > 0) {
            if (typeof DeleteManager.loadNewImages === 'function') {
                try { await DeleteManager.loadNewImages(results.success); } catch (e) { /* ignore, already notified */ }
            } else {
                console.warn('DeleteManager.loadNewImages not available');
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
        } else if (document.querySelector('.upload-results')) {
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
        const container = document.querySelector('.upload-results');
        if (!container) return;
        container.addEventListener('click', e => {
            const btn = e.target.closest('.action-btn');
            if (!btn) return;
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

    static handleImageAction(btn, card, filename) {
        if (btn.classList.contains('copy-btn')) {
            const imgUrl = card.dataset.url || card.querySelector('img')?.src;
            this.showCopyDialog(filename, imgUrl);
        } else if (btn.classList.contains('compress-btn')) {
            ImageProcessor.processSingleCompress(filename, card);
        } else if (btn.classList.contains('webp-btn')) {
            ImageProcessor.processSingleWebP(filename, card);
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
            const iconClass = { compress: 'fa-compress', webp: 'fa-image', delete: 'fa-trash' }[action] || 'fa-check';
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
        this.activeUploads = 0;
        this.uploadQueue = [];
        this.maxSize = UploadManager.CONFIG.MAX_SIZE;
        this.autoCompressEnabled = false;
        this.autoWebpEnabled = false;
        if (this.elements.imageInput && this.elements.imageInput.dataset.maxSize) {
            const parsed = parseInt(this.elements.imageInput.dataset.maxSize, 10);
            if (Number.isFinite(parsed) && parsed > 0) {
                this.maxSize = parsed;
            }
        }
        if (this.elements.imageInput) {
            this.autoCompressEnabled = this.elements.imageInput.dataset.autoCompress === '1';
            this.autoWebpEnabled = this.elements.imageInput.dataset.autoWebp === '1';
        }
        if (!this.elements.dropZone) return;
        this.bindEvents();
    }

    /**
     * 初始化DOM元素
     */
    initElements() {
        this.elements = {
            dropZone: document.getElementById('dropZone'),
            uploadForm: document.getElementById('uploadForm'),
            imageInput: document.getElementById('imageInput'),
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

    /**
     * 处理文件上传
     */
    handleFiles(files) {
        if (!this.validateEnvironment() || !this.validateFiles(files)) return;

        // 清空并显示进度区域
        this.resetProgressArea();

        // 过滤有效文件并放入并发队列
        const validFiles = this.filterValidFiles(files);
        this.batchTotal = validFiles.length;
        this.batchCompleted = 0;
        this.activeUploads = 0;
        this.uploadQueue = validFiles.slice();

        if (this.batchTotal > 0) {
            this.processUploadQueue();
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
            if (!file.type.startsWith('image/')) {
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

    /**
     * 重置进度区域
     */
    resetProgressArea() {
        this.elements.uploadProgress.innerHTML = '';
        this.elements.uploadProgress.style.display = 'block';
        requestAnimationFrame(() => this.elements.uploadProgress.classList.add('active'));
    }

    /**
     * 上传单个文件
     */
    uploadSingleFile(file) {
        const progressItem = this.createProgressItem(file.name);
        this.elements.uploadProgress.appendChild(progressItem);

        this.activeUploads += 1;

        const settle = () => {
            this.activeUploads = Math.max(0, this.activeUploads - 1);
            this.batchCompleted += 1;
            this.processUploadQueue();
            this.checkUploadComplete();
        };

        const xhr = this.createUploadRequest(file, progressItem, settle);
        xhr.send(this.createFormData(file));
    }

    /**
     * 创建进度条元素
     */
    createProgressItem(filename) {
        const item = document.createElement('div');
        item.className = 'progress-item';
        item.innerHTML = `
            <div class="progress-header">
                <span class="filename">${filename}</span>
                <span class="progress-percent">0%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-bar-inner"></div>
            </div>
            <div class="progress-status">
                <span class="progress-text">
                    <i class="fa-light fa-spinner fa-spin" style="color: var(--success)"></i>
                    <span>准备上传...</span>
                </span>
            </div>
        `;
        return item;
    }

    /**
     * 创建上传请求
     */
    createUploadRequest(file, progressItem, settle) {
        const xhr = new XMLHttpRequest();

        // 配置请求
        xhr.timeout = this.getTimeoutForFileSize(file.size);
        xhr.open('POST', '/api/upload.php');

        // 绑定事件处理
        this.bindUploadEvents(xhr, file, progressItem, settle);

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
            const file = this.uploadQueue.shift();
            this.uploadSingleFile(file);
        }
    }

    /**
     * 绑定上传事件
     */
    bindUploadEvents(xhr, file, progressItem, settle) {
        const elements = {
            progressBar: progressItem.querySelector('.progress-bar-inner'),
            progressText: progressItem.querySelector('.progress-text'),
            progressPercent: progressItem.querySelector('.progress-percent')
        };
        let settled = false;
        const settleOnce = () => {
            if (settled) return;
            settled = true;
            settle();
        };

        // 错误处理
        xhr.addEventListener('timeout', () => {
            this.handleUploadError(progressItem, `上传超时（${Math.round(xhr.timeout / 1000)} 秒）`);
            settleOnce();
        });
        xhr.addEventListener('error', () => {
            this.handleUploadError(progressItem, '网络错误');
            settleOnce();
        });
        xhr.addEventListener('abort', () => {
            this.handleUploadError(progressItem, '上传取消');
            settleOnce();
        });

        // 进度处理
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                this.updateProgress(elements, e.loaded, e.total);
            }
        });

        xhr.upload.addEventListener('load', () => {
            this.showServerProcessing(elements);
        });

        // 完成处理
        xhr.addEventListener('load', () => {
            this.handleUploadComplete(xhr, file, progressItem);
            settleOnce();
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
    updateProgress(elements, loaded, total) {
        const percent = ((loaded / total) * 100).toFixed(1);
        
        elements.progressBar.style.width = `${percent}%`;
        elements.progressPercent.textContent = `${percent}%`;
        elements.progressText.innerHTML = `
            <i class="fa-light fa-spinner fa-spin" style="color: var(--success)"></i>
            <span style="color: var(--success)">
                上传中... ${percent}%
            </span>
        `;
    }

    showServerProcessing(elements) {
        elements.progressBar.style.width = '100%';
        elements.progressPercent.textContent = '100%';

        const tasks = [];
        if (this.autoCompressEnabled) tasks.push('开始压缩');
        if (this.autoWebpEnabled) tasks.push('开始转换 WebP');

        const stageText = tasks.length > 0
            ? `上传成功，${tasks.join('，')}`
            : '上传成功，服务器处理中...';

        elements.progressText.innerHTML = `
            <i class="fa-light fa-gear fa-spin" style="color: var(--primary)"></i>
            <span style="color: var(--primary)">
                ${stageText}
            </span>
        `;
    }

    /**
     * 处理上传完成
     */
    handleUploadComplete(xhr, file, progressItem) {
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
            this.showUploadSuccess(file.name, progressItem, result);
            return true;

        } catch (err) {
            console.error('Upload error:', err);
            this.handleUploadError(progressItem, err.message);
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

    showUploadSuccess(filename, progressItem, result = null) {
        const progressBar = progressItem.querySelector('.progress-bar-inner');
        const progressText = progressItem.querySelector('.progress-text');

        progressBar.style.width = '100%';
        progressBar.classList.add('success');
        progressText.innerHTML = `
            <i class="fa-light fa-check-circle" style="color: var(--success)"></i>
            <span style="color: var(--success)">上传完成</span>
        `;

        const reportLines = this.buildProcessingReport(result);
        const hasReport = Array.isArray(reportLines) && reportLines.length > 0;
        const hasSkip = hasReport && reportLines.some(line => line.includes('未压缩') || line.includes('未转 WebP'));
        const msg = hasReport ? `${filename} 上传成功：${reportLines.join('；')}` : `${filename} 上传成功`;
        ImgEt.Utils.showNotification(msg, hasSkip ? 'warning' : 'success');

        this.fadeOutProgressItem(progressItem);
    }

    /**
     * 处理上传错误
     */
    handleUploadError(progressItem, message) {
        const progressBar = progressItem.querySelector('.progress-bar-inner');
        const progressText = progressItem.querySelector('.progress-text');
        
        progressBar.style.backgroundColor = 'var(--danger)';
        progressText.innerHTML = `
            <i class="fa-light fa-times-circle" style="color: var(--danger)"></i>
            <span style="color: var(--danger)">${message}</span>
        `;
        this.fadeOutProgressItem(progressItem);
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
    checkUploadComplete() {
        if (this.batchTotal > 0 && this.batchCompleted >= this.batchTotal && this.activeUploads === 0) {
            if (this.batchTotal > 1) {
                ImgEt.Utils.showNotification('所有文件上传完成', 'success');
            }
            setTimeout(() => location.reload(), 1000);
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
                    return `<div style="display:flex;justify-content:space-between;padding:2px 0">
                        <span style="color:var(--gray)">${p.label}</span>
                        <span style="font-weight:600">${p.value} (${formatPercent(p.value / total)})</span>
                    </div>`;
                }).join('') + `<div style="border-top:1px solid var(--border-color);padding-top:6px;margin-top:6px;color:var(--gray);font-size:0.85rem">总计: ${pairs.reduce((a,b)=>a+b.value,0)} 张</div>`;

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
 
