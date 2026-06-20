/* ============================================================
   VIKOBA MANAGEMENT SYSTEM - Enhanced app.js
   Interactive UI, Animations, and Real-time Features
   ============================================================ */

(function() {
  'use strict';

  // ============================================================
  // 1. AUTO-DISMISS ALERTS
  // ============================================================
  document.querySelectorAll('.alert').forEach(function(el) {
    setTimeout(function() {
      var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
      if (bsAlert) bsAlert.close();
    }, 4000);
  });

  // ============================================================
  // 2. CONFIRM DELETE HELPER
  // ============================================================
  window.confirmDelete = function(msg) {
    return confirm(msg || 'Are you sure you want to delete this record?');
  };

  // ============================================================
  // 3. LOAN CALCULATOR
  // ============================================================
  var loanAmountInput  = document.getElementById('loan_amount');
  var loanInterestInput = document.getElementById('loan_interest');
  var loanTotalSpan    = document.getElementById('loan_total_display');

  function updateLoanTotal() {
    if (!loanAmountInput || !loanInterestInput || !loanTotalSpan) return;
    var amt = parseFloat(loanAmountInput.value) || 0;
    var rate = parseFloat(loanInterestInput.value) || 0;
    var total = amt * (1 + rate / 100);
    loanTotalSpan.textContent = 'Total repayable: Tsh ' + total.toLocaleString('en-TZ', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  if (loanAmountInput)  loanAmountInput.addEventListener('input', updateLoanTotal);
  if (loanInterestInput) loanInterestInput.addEventListener('input', updateLoanTotal);

  // ============================================================
  // 4. TABLE SEARCH / FILTER
  // ============================================================
  var searchInput = document.getElementById('tableSearch');
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      var query = this.value.toLowerCase();
      document.querySelectorAll('.searchable-table tbody tr').forEach(function(row) {
        row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
      });
    });
  }

  // ============================================================
  // 5. ANIMATED NUMBER COUNTERS
  // ============================================================
  function animateCounter(el, target, duration) {
    if (!el) return;
    var startTime = null;
    duration = duration || 1000;

    var isCurrency = false;
    if (typeof target === 'string') {
      var match = target.replace(/[^0-9.]/g, '');
      if (match) {
        target = parseFloat(match);
        isCurrency = true;
      }
    }

    function step(timestamp) {
      if (!startTime) startTime = timestamp;
      var progress = Math.min((timestamp - startTime) / duration, 1);
      var current = Math.floor(progress * target);

      if (isCurrency) {
        el.textContent = 'Tsh ' + current.toLocaleString('en-TZ', {minimumFractionDigits: 0, maximumFractionDigits: 0});
      } else {
        el.textContent = current.toLocaleString();
      }

      if (progress < 1) {
        requestAnimationFrame(step);
      } else {
        if (isCurrency) {
          el.textContent = 'Tsh ' + target.toLocaleString('en-TZ', {minimumFractionDigits: 0, maximumFractionDigits: 0});
        } else {
          el.textContent = target.toLocaleString();
        }
      }
    }

    requestAnimationFrame(step);
  }

  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.stat-value').forEach(function(el) {
      var text = el.textContent.trim();
      var numMatch = text.replace(/[^0-9.]/g, '');
      if (numMatch && parseFloat(numMatch) > 0) {
        var target = parseFloat(numMatch);
        var isCurrency = text.indexOf('Tsh') !== -1 || text.indexOf('KSH') !== -1;
        var duration = Math.min(1500, target * 0.5);
        duration = Math.max(500, duration);

        el.setAttribute('data-target', target);
        el.setAttribute('data-currency', isCurrency ? 'true' : 'false');

        setTimeout(function() {
          animateCounter(el, isCurrency ? 'Tsh ' + target : target, duration);
        }, 200);
      }
    });
  });

  // ============================================================
  // 6. CARD TILT EFFECT
  // ============================================================
  document.querySelectorAll('.card-hover-tilt').forEach(function(card) {
    card.addEventListener('mousemove', function(e) {
      var rect = card.getBoundingClientRect();
      var x = e.clientX - rect.left;
      var y = e.clientY - rect.top;
      var centerX = rect.width / 2;
      var centerY = rect.height / 2;
      var rotateX = (y - centerY) / centerY * -5;
      var rotateY = (x - centerX) / centerX * 5;
      card.style.transform = 'perspective(1000px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) translateY(-4px)';
    });

    card.addEventListener('mouseleave', function() {
      card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) translateY(0)';
    });
  });

  // ============================================================
  // 7. LIVE NOTIFICATION & MESSAGE POLLING
  // ============================================================
  (function() {
    var notifBadge = document.getElementById('notifBadge');
    var msgBadge   = document.getElementById('msgBadge');
    var notifList  = document.getElementById('notifList');

    if (!notifBadge && !msgBadge) return;

    function updateBadges() {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', 'ajax_notifications.php', true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.onload = function() {
        if (xhr.status === 200) {
          try {
            var data = JSON.parse(xhr.responseText);
            if (data.error) return;

            if (notifBadge) {
              if (data.notifications > 0) {
                notifBadge.textContent = data.notifications;
                notifBadge.style.display = 'inline';
                notifBadge.style.animation = 'none';
                setTimeout(function() { notifBadge.style.animation = ''; }, 10);
              } else {
                notifBadge.style.display = 'none';
              }
            }

            if (msgBadge) {
              if (data.messages > 0) {
                msgBadge.textContent = data.messages;
                msgBadge.style.display = 'inline';
              } else {
                msgBadge.style.display = 'none';
              }
            }

            document.querySelectorAll('.sidebar .nav-link .badge.bg-danger').forEach(function(badge) {
              var parent = badge.closest('.nav-link');
              if (parent) {
                var href = parent.getAttribute('href') || '';
                if (href.indexOf('notifications') !== -1) {
                  badge.textContent = data.notifications;
                  badge.style.display = data.notifications > 0 ? 'inline' : 'none';
                }
                if (href.indexOf('messages') !== -1) {
                  badge.textContent = data.messages;
                  badge.style.display = data.messages > 0 ? 'inline' : 'none';
                }
              }
            });

          } catch(e) {}
        }
      };
      xhr.send();
    }

    updateBadges();
    setInterval(updateBadges, 15000);
  })();

  // ============================================================
  // 8. THEME TOGGLE WITH ANIMATION
  // ============================================================
  (function(){
    var toggle = document.getElementById('themeToggle');
    if (!toggle) return;

    function applyTheme(t) {
      if (t === 'dark') {
        document.documentElement.classList.add('theme-dark');
        toggle.innerHTML = '' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
          '<circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="1.5"/>' +
          '<path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>' +
          '</svg> Light';
      } else {
        document.documentElement.classList.remove('theme-dark');
        toggle.innerHTML = '' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
          '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
          '</svg> Dark';
      }
      localStorage.setItem('vikoba_theme', t);
    }

    toggle.addEventListener('click', function(){
      var cur = localStorage.getItem('vikoba_theme') || 'light';
      applyTheme(cur === 'light' ? 'dark' : 'light');
    });

    var saved = localStorage.getItem('vikoba_theme') || 'light';
    applyTheme(saved);
  })();

  // ============================================================
  // 9. SMOOTH SCROLL TO TOP
  // ============================================================
  (function() {
    var scrollBtn = document.getElementById('scrollToTop');
    if (!scrollBtn) {
      scrollBtn = document.createElement('button');
      scrollBtn.id = 'scrollToTop';
      scrollBtn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><polyline points="18 15 12 9 6 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      scrollBtn.style.cssText = 'position:fixed;bottom:24px;right:24px;width:44px;height:44px;border-radius:50%;background:#185FA5;color:#fff;border:none;cursor:pointer;z-index:9999;display:none;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(24,95,165,0.3);transition:all 0.25s;';
      document.body.appendChild(scrollBtn);
    }

    window.addEventListener('scroll', function() {
      if (window.scrollY > 300) {
        scrollBtn.style.display = 'flex';
      } else {
        scrollBtn.style.display = 'none';
      }
    });

    scrollBtn.addEventListener('click', function() {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  })();

  // ============================================================
  // 10. TOAST NOTIFICATION SYSTEM
  // ============================================================
  window.showToast = function(message, type) {
    type = type || 'success';
    var container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;max-width:400px;';
      document.body.appendChild(container);
    }

    var colors = {
      success: { bg: '#EAF3DE', color: '#2E8B3A' },
      error: { bg: '#FCEBEB', color: '#A32D2D' },
      warning: { bg: '#FAEEDA', color: '#D4A017' },
      info: { bg: '#E6F1FB', color: '#185FA5' }
    };

    var c = colors[type] || colors.info;
    var toast = document.createElement('div');
    toast.style.cssText = 'background:' + c.bg + ';color:' + c.color + ';padding:14px 18px;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.12);display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;animation:slideInRight 0.3s ease;cursor:pointer;border-left:4px solid ' + c.color;

    var iconSvg = '';
    if (type === 'success') iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><polyline points="20 6 9 17 4 12" stroke="' + c.color + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    else iconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 9v4M12 17h.01M21 12A9 9 0 1 1 3 12a9 9 0 0 1 18 0z" stroke="' + c.color + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    toast.innerHTML = iconSvg + ' ' + message;

    // Add keyframes if not exists
    if (!document.getElementById('toastKeyframes')) {
      var style = document.createElement('style');
      style.id = 'toastKeyframes';
      style.textContent = '@keyframes slideInRight { from { opacity: 0; transform: translateX(100px); } to { opacity: 1; transform: translateX(0); } } @keyframes slideOutRight { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(100px); } }';
      document.head.appendChild(style);
    }

    container.appendChild(toast);

    setTimeout(function() {
      toast.style.animation = 'slideOutRight 0.3s ease forwards';
      setTimeout(function() { toast.remove(); }, 300);
    }, 4000);

    toast.addEventListener('click', function() {
      toast.style.animation = 'slideOutRight 0.3s ease forwards';
      setTimeout(function() { toast.remove(); }, 300);
    });
  };

  // ============================================================
  // 11. SIDEBAR ACTIVE LINK HIGHLIGHT
  // ============================================================
  (function() {
    var currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
      var href = link.getAttribute('href');
      if (href && currentPath.indexOf(href) !== -1) {
        link.classList.add('active');
      }
    });
  })();

  // ============================================================
  // 12. CLOSE SIDEBAR ON MOBILE WHEN LINK CLICKED
  // ============================================================
  document.querySelectorAll('.sidebar .nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
      var sidebar = document.getElementById('sidebar');
      if (sidebar && window.innerWidth <= 992) {
        sidebar.classList.remove('open');
      }
    });
  });

  // ============================================================
  // 13. TOOLTIP INITIALIZATION
  // ============================================================
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
    try {
      new bootstrap.Tooltip(el);
    } catch(e) {}
  });

  // ============================================================
  // 14. POPOVER INITIALIZATION
  // ============================================================
  document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el) {
    try {
      new bootstrap.Popover(el);
    } catch(e) {}
  });

  // ============================================================
  // 15. KEYBOARD SHORTCUTS
  // ============================================================
  document.addEventListener('keydown', function(e) {
    // Ctrl+K - Focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      var search = document.getElementById('tableSearch');
      if (search) search.focus();
    }
  });

  // ============================================================
  // 16. AUTO-RESIZE TEXTAREAS
  // ============================================================
  document.querySelectorAll('textarea.auto-resize').forEach(function(ta) {
    ta.addEventListener('input', function() {
      this.style.height = 'auto';
      this.style.height = this.scrollHeight + 'px';
    });
  });

  // ============================================================
  // 17. COPY TO CLIPBOARD
  // ============================================================
  document.querySelectorAll('[data-copy]').forEach(function(el) {
    el.addEventListener('click', function() {
      var text = this.getAttribute('data-copy');
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
          if (window.showToast) showToast('Copied to clipboard!', 'success');
        });
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        if (window.showToast) showToast('Copied to clipboard!', 'success');
      }
    });
  });

  // ============================================================
  // 18. PAGE LOADED ANIMATION
  // ============================================================
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelector('.main-content')?.classList.add('page-enter');

    // Add staggered animation to cards
    document.querySelectorAll('.card').forEach(function(card, index) {
      card.style.animationDelay = (index * 0.05) + 's';
    });
  });

  console.log('%c VIKOBA System %c v2.0 Enhanced UI ',
    'background:#185FA5;color:#fff;padding:4px 8px;border-radius:4px 0 0 4px;font-weight:bold;',
    'background:#0C447C;color:#fff;padding:4px 8px;border-radius:0 4px 4px 0;'
  );

})();