Espo.define('turbosms:views/fields/scheduling-field', 'views/scheduled-job/fields/scheduling', function (Dep) {
    return class extends Dep {
        afterRender() {
            super.afterRender();
            this.$el.find('.main-element').on('input', event => {
                this.model.set(this.name, event.currentTarget.value);
            });
            if (!this.isEditMode() || this.readOnly || this.$el.find('[data-action="sync"]').length) return;
            const $button = $('<button>', {type: 'button', class: 'btn btn-default btn-icon', 'data-action': 'sync', title: this.translate('turboSmsRunSync', 'labels', 'Integration')}).append($('<span>', {class: 'fas fa-sync-alt fa-sm'}));
            this.$el.find('.main-element').after($button);
            $button.on('click', () => this.runSync($button));
        }

        runSync($button) {
            $button.prop('disabled', true);
            Espo.Ajax.postRequest('TurboSMS/sync').then(response => Espo.Ui.success(this.translate(response?.scheduled ? 'turboSmsSyncScheduled' : 'turboSmsSyncStarted', 'messages', 'Integration'))).catch(() => Espo.Ui.error(this.translate('Error'))).finally(() => $button.prop('disabled', false));
        }
    };
});
