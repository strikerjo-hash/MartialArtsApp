/**
 * TrainingWizard — Shared engine for admin/student/parent training wizards.
 *
 * Usage:
 *   const wizard = new TrainingWizard({
 *       storageKey: 'admin_training_progress',
 *       steps: [ { title, icon, substeps: [{ title, description, highlights, tips, link, linkTarget }] } ],
 *       sidebarEl:  document.getElementById('wizard-sidebar'),
 *       contentEl:  document.getElementById('wizard-content'),
 *       progressEl: document.getElementById('wizard-progress'),
 *   });
 *   wizard.init();
 */
class TrainingWizard {

    constructor(config) {
        this.storageKey  = config.storageKey;
        this.steps       = config.steps;          // Array of group objects
        this.sidebarEl   = config.sidebarEl;
        this.contentEl   = config.contentEl;
        this.progressEl  = config.progressEl;
        this.currentStep = 0;
        this.completedSteps = new Set();
        this._loadProgress();
    }

    /* =========================================================
       localStorage Persistence
       ========================================================= */

    _loadProgress() {
        try {
            var data = JSON.parse(localStorage.getItem(this.storageKey));
            if (data) {
                this.currentStep    = typeof data.currentStep === 'number' ? data.currentStep : 0;
                this.completedSteps = new Set(Array.isArray(data.completedSteps) ? data.completedSteps : []);
            }
        } catch (e) { /* ignore */ }
    }

    _saveProgress() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify({
                currentStep:    this.currentStep,
                completedSteps: Array.from(this.completedSteps),
                lastVisited:    new Date().toISOString()
            }));
        } catch (e) { /* ignore */ }
    }

    /* =========================================================
       Helpers
       ========================================================= */

    /** Flatten all groups into a single array of substep objects. */
    _getAllSteps() {
        var flat = [];
        for (var gi = 0; gi < this.steps.length; gi++) {
            var group = this.steps[gi];
            for (var si = 0; si < group.substeps.length; si++) {
                var sub = group.substeps[si];
                flat.push({
                    title:       sub.title,
                    description: sub.description,
                    highlights:  sub.highlights || [],
                    tips:        sub.tips || [],
                    link:        sub.link || '',
                    linkTarget:  sub.linkTarget || '_blank',
                    groupIndex:  gi,
                    groupTitle:  group.title,
                    groupIcon:   group.icon
                });
            }
        }
        return flat;
    }

    getTotalSteps() {
        return this._getAllSteps().length;
    }

    getCompletionPercent() {
        var total = this.getTotalSteps();
        return total === 0 ? 0 : Math.round((this.completedSteps.size / total) * 100);
    }

    /* =========================================================
       Navigation
       ========================================================= */

    goToStep(index) {
        this.currentStep = index;
        this._saveProgress();
        this.render();
        // Scroll content into view on mobile
        if (window.innerWidth <= 768 && this.contentEl) {
            this.contentEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    nextStep() {
        this.markCurrentComplete();
        if (this.currentStep < this.getTotalSteps() - 1) {
            this.currentStep++;
        }
        this._saveProgress();
        this.render();
    }

    prevStep() {
        if (this.currentStep > 0) {
            this.currentStep--;
        }
        this._saveProgress();
        this.render();
    }

    markCurrentComplete() {
        this.completedSteps.add(this.currentStep);
        this._saveProgress();
    }

    markStepComplete(index) {
        this.completedSteps.add(index);
        this._saveProgress();
        this.render();
    }

    resetProgress() {
        this.currentStep = 0;
        this.completedSteps = new Set();
        this._saveProgress();
        this.render();
    }

    /* =========================================================
       Rendering
       ========================================================= */

    init() {
        this.render();
    }

    render() {
        this._renderSidebar();
        this._renderContent();
        this._renderProgress();
    }

    /* ---------- Progress Bar ---------- */

    _renderProgress() {
        var pct   = this.getCompletionPercent();
        var total = this.getTotalSteps();
        var done  = this.completedSteps.size;

        var html = '<div class="flex items-center justify-between mb-2">'
            + '<span class="text-sm font-medium text-gray-700">' + done + ' of ' + total + ' steps completed</span>'
            + '<span class="text-sm font-bold" style="color: var(--wizard-primary, #3b82f6);">' + pct + '%</span>'
            + '</div>'
            + '<div class="w-full bg-gray-200 rounded-full h-3">'
            + '<div class="h-3 rounded-full wizard-progress-fill" style="width: ' + pct + '%; background-color: var(--wizard-primary, #3b82f6);"></div>'
            + '</div>';

        this.progressEl.innerHTML = html;
    }

    /* ---------- Sidebar ---------- */

    _renderSidebar() {
        var html = '';
        var flatIndex = 0;
        var self = this;

        for (var gi = 0; gi < this.steps.length; gi++) {
            var group = this.steps[gi];
            var groupStart = flatIndex;
            var groupEnd   = flatIndex + group.substeps.length - 1;

            // Check group state
            var allComplete = true;
            var isCurrent   = false;
            for (var ci = groupStart; ci <= groupEnd; ci++) {
                if (!this.completedSteps.has(ci)) allComplete = false;
                if (ci === this.currentStep) isCurrent = true;
            }

            // Group header
            var headerColor = isCurrent ? 'text-blue-700' : (allComplete ? 'text-green-700' : 'text-gray-600');
            html += '<div class="wizard-sidebar-group">';
            html += '<div class="flex items-center gap-2 px-3 py-2 text-sm font-bold ' + headerColor + '">';
            html += '<span>' + group.icon + '</span>';
            html += '<span class="truncate">' + this._esc(group.title) + '</span>';
            if (allComplete) html += '<span class="ml-auto text-green-500 flex-shrink-0">&#10003;</span>';
            html += '</div>';

            // Substeps
            for (var si = 0; si < group.substeps.length; si++) {
                var idx      = flatIndex + si;
                var isActive = (idx === this.currentStep);
                var isDone   = this.completedSteps.has(idx);

                var cls = 'wizard-sidebar-step pl-8 pr-3 py-1.5 text-sm cursor-pointer rounded-lg flex items-center gap-2';
                if (isActive) cls += ' bg-blue-50 text-blue-700 font-semibold';
                else if (isDone) cls += ' text-green-600';
                else cls += ' text-gray-500 hover:bg-gray-50';

                html += '<div class="' + cls + '" data-step="' + idx + '" onclick="wizard.goToStep(' + idx + ')">';
                if (isDone) html += '<span class="text-green-500 text-xs flex-shrink-0">&#10003;</span>';
                else if (isActive) html += '<span class="w-2 h-2 bg-blue-500 rounded-full flex-shrink-0"></span>';
                else html += '<span class="w-2 h-2 bg-gray-300 rounded-full flex-shrink-0"></span>';
                html += '<span class="truncate">' + this._esc(group.substeps[si].title) + '</span>';
                html += '</div>';
            }

            html += '</div>';
            flatIndex += group.substeps.length;
        }

        this.sidebarEl.innerHTML = html;
    }

    /* ---------- Content ---------- */

    _renderContent() {
        var allSteps = this._getAllSteps();
        var step     = allSteps[this.currentStep];
        if (!step) return;

        var isDone = this.completedSteps.has(this.currentStep);
        var total  = this.getTotalSteps();
        var pct    = this.getCompletionPercent();

        // Completion banner
        var html = '';
        if (pct === 100) {
            html += '<div class="wizard-complete-banner">'
                + '<h3>&#127881; Training Complete!</h3>'
                + '<p>You\'ve completed all ' + total + ' training steps. You can revisit any step at any time.</p>'
                + '</div>';
        }

        // Breadcrumb
        html += '<div class="mb-6">'
            + '<div class="flex items-center gap-2 text-sm text-gray-500 mb-2">'
            + '<span>' + step.groupIcon + ' ' + this._esc(step.groupTitle) + '</span>'
            + '<span class="text-gray-300">&rsaquo;</span>'
            + '<span>Step ' + (this.currentStep + 1) + ' of ' + total + '</span>'
            + '</div>'
            + '<h2 class="text-2xl font-bold text-gray-800">' + this._esc(step.title) + '</h2>'
            + '</div>';

        // Content body
        html += '<div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">';

        // Description
        html += '<p class="text-gray-600 mb-4 leading-relaxed">' + step.description + '</p>';

        // Feature highlights
        if (step.highlights.length > 0) {
            html += '<div class="wizard-highlights">';
            html += '<h4>Key Features:</h4>';
            html += '<ul class="space-y-2">';
            for (var i = 0; i < step.highlights.length; i++) {
                html += '<li class="flex items-start gap-2">'
                    + '<span class="mt-0.5 flex-shrink-0" style="color: var(--wizard-primary, #3b82f6);">&#8226;</span>'
                    + '<span>' + step.highlights[i] + '</span>'
                    + '</li>';
            }
            html += '</ul></div>';
        }

        // Pro tips
        if (step.tips.length > 0) {
            html += '<div class="wizard-tips">';
            html += '<h4>&#128161; Pro Tips:</h4>';
            for (var t = 0; t < step.tips.length; t++) {
                html += '<p>' + step.tips[t] + '</p>';
            }
            html += '</div>';
        }

        html += '</div>'; // close content body

        // Action buttons
        html += '<div class="flex items-center justify-between flex-wrap gap-3">';

        // Left: Previous
        html += '<div>';
        if (this.currentStep > 0) {
            html += '<button onclick="wizard.prevStep()" class="wizard-btn wizard-btn-secondary">&larr; Previous</button>';
        }
        html += '</div>';

        // Right: Try It + Mark Complete + Next
        html += '<div class="flex gap-2 items-center flex-wrap">';

        // "Try It" deep link
        if (step.link) {
            html += '<a href="' + step.link + '" target="' + step.linkTarget + '" '
                + 'class="wizard-btn wizard-btn-success" '
                + 'onclick="wizard.markStepComplete(' + this.currentStep + ')">'
                + 'Try It &rarr;</a>';
        }

        // Mark complete / completed badge
        if (!isDone) {
            html += '<button onclick="wizard.markStepComplete(' + this.currentStep + '); wizard.render();" '
                + 'class="wizard-btn wizard-btn-ghost">Mark Complete</button>';
        } else {
            html += '<span class="text-green-600 text-sm font-medium flex items-center gap-1">&#10003; Completed</span>';
        }

        // Next / Finish
        if (this.currentStep < total - 1) {
            html += '<button onclick="wizard.nextStep()" class="wizard-btn wizard-btn-primary">Next &rarr;</button>';
        } else {
            html += '<button onclick="wizard.markCurrentComplete(); wizard.render();" '
                + 'class="wizard-btn wizard-btn-success">Finish Training &#127881;</button>';
        }

        html += '</div></div>';

        this.contentEl.innerHTML = html;
    }

    /* ---------- Utility ---------- */

    _esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
}
