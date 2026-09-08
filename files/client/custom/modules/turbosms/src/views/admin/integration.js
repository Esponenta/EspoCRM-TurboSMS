Espo.define('turbosms:views/admin/integration', 'views/admin/integrations/edit', function (Dep) {
    return class extends Dep {
        afterRender() {
            super.afterRender();
            this.listenTo(
                this.model,
                'change:enabled change:turboSmsLowBalanceEnabled change:turboSmsSendType',
                () => this.controlFields()
            );
            this.controlFields();
        }

        createFieldView(type, name, readOnly, params) {
            super.createFieldView(type, name, readOnly ?? Boolean(params?.readOnly), params);
        }

        controlFields() {
            if (!this.model.get('enabled')) {
                return;
            }

            const alertsEnabled = Boolean(this.model.get('turboSmsLowBalanceEnabled'));
            this.toggleFields([
                'turboSmsLowBalanceThreshold',
                'turboSmsNotificationUsers',
                'turboSmsNotificationTeams',
            ], alertsEnabled);

            const sendType = this.model.get('turboSmsSendType');
            this.toggleFields(['turboSmsSenderSms'], sendType === 'sms' || sendType === 'hybrid');
            this.toggleFields(['turboSmsSenderViber'], sendType === 'viber' || sendType === 'hybrid');
        }

        toggleFields(fieldList, visible) {
            fieldList.forEach(name => visible ? this.showField(name) : this.hideField(name));
        }
    };
});
