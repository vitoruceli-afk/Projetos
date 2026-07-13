// Função para mostrar alertas customizados
function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.textContent = message;
    
    const mainContent = document.querySelector('.main-content');
    if (mainContent) {
        mainContent.insertBefore(alertDiv, mainContent.firstChild);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
}

// Função para fazer requisições AJAX
function ajax(url, method = 'GET', data = null) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
        
        if (method !== 'GET' && data) {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        }
        
        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    resolve(response);
                } catch (e) {
                    resolve(xhr.responseText);
                }
            } else {
                reject(new Error(`HTTP ${xhr.status}: ${xhr.statusText}`));
            }
        };
        
        xhr.onerror = function() {
            reject(new Error('Erro na requisição'));
        };
        
        if (method !== 'GET' && data) {
            const params = new URLSearchParams(data);
            xhr.send(params.toString());
        } else {
            xhr.send();
        }
    });
}

// Função para formatar data
function formatDate(date) {
    const d = new Date(date);
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${day}/${month}/${year}`;
}

// Função para formatar data e hora
function formatDateTime(dateTime) {
    const d = new Date(dateTime);
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    return `${day}/${month}/${year} ${hours}:${minutes}`;
}

// Função para validar email
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Função para validar formulário simples
function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('[required]');
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            input.style.borderColor = '#e74c3c';
            isValid = false;
        } else {
            input.style.borderColor = '';
        }
    });
    
    return isValid;
}

// Evento para marcar link ativo na sidebar
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = new URLSearchParams(window.location.search).get('page');
    
    if (currentPage) {
        const links = document.querySelectorAll('.sidebar-menu a');
        links.forEach(link => {
            const href = new URLSearchParams(new URL(link.href).search).get('page');
            if (href === currentPage) {
                link.classList.add('active');
            }
        });
    }
});

// Função para copiar ao clipboard
function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showAlert('Copiado para a área de transferência!', 'success');
        });
    } else {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showAlert('Copiado para a área de transferência!', 'success');
    }
}

// Função para gerar UUID
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

// Função para formatar bytes
function formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

// Previne dupla submissão de formulários
document.addEventListener('submit', function(e) {
    const form = e.target;
    const buttons = form.querySelectorAll('[type="submit"]');
    
    buttons.forEach(button => {
        button.setAttribute('disabled', 'disabled');
        button.style.opacity = '0.6';
    });
    
    setTimeout(() => {
        buttons.forEach(button => {
            button.removeAttribute('disabled');
            button.style.opacity = '1';
        });
    }, 2000);
});

// Export para uso em outros arquivos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        showAlert,
        ajax,
        formatDate,
        formatDateTime,
        validateEmail,
        validateForm,
        copyToClipboard,
        generateUUID,
        formatBytes
    };
}
