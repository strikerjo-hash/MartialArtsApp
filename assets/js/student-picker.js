/**
 * StudentPicker — Reusable searchable student selector component.
 *
 * Two modes:
 *   Preloaded — pass a `data` array of students (client-side filtering).
 *   AJAX      — pass an `ajaxUrl` to fetch from the server on each keystroke.
 *
 * Usage:
 *   StudentPicker.init({
 *     container:   '#my-wrapper',            // CSS selector or DOM element
 *     inputName:   'student_id',             // name for the hidden <input>
 *     placeholder: 'Search students...',
 *     data:        [{id,name,email,...}],     // preloaded mode
 *     ajaxUrl:     'ajax_student_search.php', // AJAX mode (overrides data)
 *     ajaxParams:  {context:'active'},        // extra GET params for AJAX
 *     onSelect:    function(student){},       // callback after selection
 *     renderOption: function(s){ return html },
 *     required:    true,                      // HTML5 required attribute
 *     allowClear:  true
 *   });
 */
var StudentPicker = (function () {
    'use strict';

    var instances = {};
    var uid = 0;

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Default option renderer                                            */
    /* ------------------------------------------------------------------ */

    function defaultRenderOption(s) {
        var html = '<div class="sp-option-name">' + esc(s.name) + '</div>';
        if (s.email) {
            html += '<div class="sp-option-sub">' + esc(s.email) + '</div>';
        }
        if (s.extra) {
            html += '<div class="sp-option-sub">' + esc(s.extra) + '</div>';
        }
        return html;
    }

    /* ------------------------------------------------------------------ */
    /*  Init                                                               */
    /* ------------------------------------------------------------------ */

    function init(opts) {
        var id = 'sp_' + (++uid);
        var container = typeof opts.container === 'string'
            ? document.querySelector(opts.container)
            : opts.container;

        if (!container) {
            console.error('[StudentPicker] Container not found:', opts.container);
            return null;
        }

        var state = {
            id: id,
            container: container,
            inputName: opts.inputName || 'student_id',
            placeholder: opts.placeholder || 'Search students\u2026',
            data: opts.data || null,
            ajaxUrl: opts.ajaxUrl || null,
            ajaxParams: opts.ajaxParams || {},
            onSelect: opts.onSelect || null,
            renderOption: opts.renderOption || defaultRenderOption,
            required: opts.required !== false,
            allowClear: opts.allowClear !== false,
            selectedStudent: null,
            results: [],
            highlightIdx: -1,
            open: false,
            loading: false,
            abortCtrl: null
        };

        render(state);
        bindEvents(state);
        instances[id] = state;
        return { id: id, getValue: function () { return getSelectedValue(state); }, clear: function () { clearSelection(state); }, destroy: function () { destroyInstance(state); } };
    }

    /* ------------------------------------------------------------------ */
    /*  Render DOM                                                         */
    /* ------------------------------------------------------------------ */

    function render(st) {
        var c = st.container;
        c.classList.add('sp-wrapper');
        c.innerHTML =
            '<div class="sp-selected hidden" id="' + st.id + '_sel">' +
            '  <span class="sp-sel-name"></span>' +
            '  <button type="button" class="sp-clear" title="Clear">&times;</button>' +
            '</div>' +
            '<div class="sp-input-wrap" id="' + st.id + '_iw">' +
            '  <svg class="sp-search-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>' +
            '  <input type="text" class="sp-input" id="' + st.id + '_input" autocomplete="off" placeholder="' + esc(st.placeholder) + '">' +
            '  <div class="sp-spinner hidden" id="' + st.id + '_spin"></div>' +
            '</div>' +
            '<input type="hidden" name="' + esc(st.inputName) + '" id="' + st.id + '_val"' + (st.required ? ' required' : '') + '>' +
            '<div class="sp-dropdown hidden" id="' + st.id + '_dd"></div>';
    }

    /* ------------------------------------------------------------------ */
    /*  Event binding                                                      */
    /* ------------------------------------------------------------------ */

    function bindEvents(st) {
        var input = document.getElementById(st.id + '_input');
        var dd = document.getElementById(st.id + '_dd');
        var clearBtn = st.container.querySelector('.sp-clear');

        // Typing
        var doSearch = st.ajaxUrl ? debounce(function () { ajaxSearch(st); }, 300) : function () { localSearch(st); };
        input.addEventListener('input', doSearch);

        // Focus — show dropdown
        input.addEventListener('focus', function () {
            if (st.selectedStudent) return;
            if (st.data && !st.ajaxUrl) {
                localSearch(st);
            } else if (st.ajaxUrl && input.value.length === 0) {
                // Show initial results for AJAX mode on focus
                ajaxSearch(st);
            }
        });

        // Keyboard nav
        input.addEventListener('keydown', function (e) {
            if (!st.open) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                st.highlightIdx = Math.min(st.highlightIdx + 1, st.results.length - 1);
                updateHighlight(st);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                st.highlightIdx = Math.max(st.highlightIdx - 1, 0);
                updateHighlight(st);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (st.highlightIdx >= 0 && st.highlightIdx < st.results.length) {
                    selectStudent(st, st.results[st.highlightIdx]);
                }
            } else if (e.key === 'Escape') {
                closeDropdown(st);
            }
        });

        // Clear
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                clearSelection(st);
            });
        }

        // Click outside → close
        document.addEventListener('click', function (e) {
            if (!st.container.contains(e.target)) {
                closeDropdown(st);
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Local (client-side) search                                         */
    /* ------------------------------------------------------------------ */

    function localSearch(st) {
        var input = document.getElementById(st.id + '_input');
        var q = (input.value || '').toLowerCase().trim();
        var results;

        if (q.length === 0) {
            results = st.data.slice(0, 50);
        } else {
            results = st.data.filter(function (s) {
                var haystack = (s.name + ' ' + (s.email || '') + ' ' + (s.username || '') + ' ' + (s.extra || '')).toLowerCase();
                return haystack.indexOf(q) !== -1;
            }).slice(0, 50);
        }

        st.results = results;
        st.highlightIdx = -1;
        renderDropdown(st, results, st.data.length > 50 && q.length === 0);
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX search                                                        */
    /* ------------------------------------------------------------------ */

    function ajaxSearch(st) {
        var input = document.getElementById(st.id + '_input');
        var spin = document.getElementById(st.id + '_spin');
        var q = (input.value || '').trim();

        // Abort previous request
        if (st.abortCtrl) { try { st.abortCtrl.abort(); } catch (e) {} }

        st.loading = true;
        spin.classList.remove('hidden');

        var params = new URLSearchParams(st.ajaxParams);
        params.set('q', q);

        st.abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;

        fetch(st.ajaxUrl + '?' + params.toString(), {
            signal: st.abortCtrl ? st.abortCtrl.signal : undefined
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                st.loading = false;
                spin.classList.add('hidden');
                if (data.success && data.students) {
                    st.results = data.students;
                    st.highlightIdx = -1;
                    renderDropdown(st, data.students, false);
                }
            })
            .catch(function (err) {
                if (err.name === 'AbortError') return;
                st.loading = false;
                spin.classList.add('hidden');
            });
    }

    /* ------------------------------------------------------------------ */
    /*  Dropdown rendering                                                 */
    /* ------------------------------------------------------------------ */

    function renderDropdown(st, results, showHint) {
        var dd = document.getElementById(st.id + '_dd');

        if (results.length === 0) {
            var input = document.getElementById(st.id + '_input');
            var q = (input.value || '').trim();
            dd.innerHTML = '<div class="sp-empty">' + (q ? 'No students found' : 'Type to search\u2026') + '</div>';
            dd.classList.remove('hidden');
            st.open = true;
            return;
        }

        var html = '';
        if (showHint) {
            html += '<div class="sp-hint">Showing first 50 — type to narrow results</div>';
        }

        for (var i = 0; i < results.length; i++) {
            var s = results[i];
            html += '<div class="sp-option' + (i === st.highlightIdx ? ' sp-highlight' : '') + '" data-idx="' + i + '">';
            html += '<div class="sp-opt-avatar">' + esc((s.name || '??').charAt(0).toUpperCase()) + '</div>';
            html += '<div class="sp-opt-content">' + st.renderOption(s) + '</div>';
            html += '</div>';
        }

        dd.innerHTML = html;
        dd.classList.remove('hidden');
        st.open = true;

        // Bind click handlers on options
        var opts = dd.querySelectorAll('.sp-option');
        for (var j = 0; j < opts.length; j++) {
            (function (idx) {
                opts[idx].addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    selectStudent(st, results[idx]);
                });
                opts[idx].addEventListener('mouseenter', function () {
                    st.highlightIdx = idx;
                    updateHighlight(st);
                });
            })(j);
        }
    }

    function updateHighlight(st) {
        var dd = document.getElementById(st.id + '_dd');
        var opts = dd.querySelectorAll('.sp-option');
        for (var i = 0; i < opts.length; i++) {
            if (i === st.highlightIdx) {
                opts[i].classList.add('sp-highlight');
                opts[i].scrollIntoView({ block: 'nearest' });
            } else {
                opts[i].classList.remove('sp-highlight');
            }
        }
    }

    function closeDropdown(st) {
        var dd = document.getElementById(st.id + '_dd');
        dd.classList.add('hidden');
        st.open = false;
        st.highlightIdx = -1;
    }

    /* ------------------------------------------------------------------ */
    /*  Selection                                                          */
    /* ------------------------------------------------------------------ */

    function selectStudent(st, student) {
        st.selectedStudent = student;

        // Set hidden input value
        document.getElementById(st.id + '_val').value = student.id;

        // Show selected chip
        var sel = document.getElementById(st.id + '_sel');
        sel.querySelector('.sp-sel-name').textContent = student.name;
        sel.classList.remove('hidden');

        // Hide search input
        document.getElementById(st.id + '_iw').classList.add('hidden');

        closeDropdown(st);

        // Callback
        if (st.onSelect) {
            st.onSelect(student);
        }
    }

    function clearSelection(st) {
        st.selectedStudent = null;
        document.getElementById(st.id + '_val').value = '';

        // Hide chip, show input
        document.getElementById(st.id + '_sel').classList.add('hidden');
        var iw = document.getElementById(st.id + '_iw');
        iw.classList.remove('hidden');

        var input = document.getElementById(st.id + '_input');
        input.value = '';
        input.focus();

        // Callback
        if (st.onSelect) {
            st.onSelect(null);
        }
    }

    function getSelectedValue(st) {
        return st.selectedStudent ? st.selectedStudent.id : null;
    }

    function destroyInstance(st) {
        st.container.innerHTML = '';
        st.container.classList.remove('sp-wrapper');
        delete instances[st.id];
    }

    /* ------------------------------------------------------------------ */
    /*  Inject styles once                                                 */
    /* ------------------------------------------------------------------ */

    (function injectStyles() {
        if (document.getElementById('sp-styles')) return;
        var css = document.createElement('style');
        css.id = 'sp-styles';
        css.textContent =
            '.sp-wrapper{position:relative;width:100%}' +
            '.sp-input-wrap{position:relative;display:flex;align-items:center}' +
            '.sp-search-icon{position:absolute;left:10px;width:16px;height:16px;color:#9ca3af;pointer-events:none}' +
            '.sp-input{width:100%;padding:8px 12px 8px 32px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;outline:none;background:#fff;transition:border-color .15s}' +
            '.sp-input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}' +
            '.sp-input::placeholder{color:#9ca3af}' +
            '.sp-spinner{position:absolute;right:10px;width:16px;height:16px;border:2px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:sp-spin .6s linear infinite}' +
            '@keyframes sp-spin{to{transform:rotate(360deg)}}' +
            '.sp-dropdown{position:absolute;top:100%;left:0;right:0;margin-top:4px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 10px 25px -5px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05);max-height:260px;overflow-y:auto;z-index:9999}' +
            '.sp-option{display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;transition:background .1s}' +
            '.sp-option:hover,.sp-highlight{background:#f3f4f6}' +
            '.sp-opt-avatar{width:32px;height:32px;border-radius:50%;background:#ede9fe;color:#7c3aed;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0}' +
            '.sp-opt-content{flex:1;min-width:0}' +
            '.sp-option-name{font-size:14px;font-weight:500;color:#1f2937}' +
            '.sp-option-sub{font-size:12px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
            '.sp-empty,.sp-hint{padding:12px 16px;text-align:center;color:#9ca3af;font-size:13px}' +
            '.sp-selected{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#f9fafb}' +
            '.sp-sel-name{flex:1;font-size:14px;font-weight:500;color:#1f2937}' +
            '.sp-clear{background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;padding:0 4px;line-height:1}' +
            '.sp-clear:hover{color:#ef4444}' +
            '.hidden{display:none!important}';
        document.head.appendChild(css);
    })();

    /* ------------------------------------------------------------------ */
    /*  Public API                                                         */
    /* ------------------------------------------------------------------ */

    return { init: init };
})();
