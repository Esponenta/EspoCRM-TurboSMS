Espo.define('turbosms:handlers/sms/transport-section', [], function () {
    return class {
        constructor(view) {
            this.view = view;
        }

        process() {
            if (Array.isArray(this.view.detailLayout)) {
                if (this.modifyLayout(this.view.detailLayout)) {
                    this.activate();
                }
                return;
            }

            if (this.attached || typeof this.view.modifyDetailLayout !== 'function') {
                return;
            }

            const original = this.view.modifyDetailLayout;
            this.view.hasModifyDetailLayout = true;
            this.view.modifyDetailLayout = layout => {
                original.call(this.view, layout);
                if (this.modifyLayout(layout)) {
                    this.activate();
                }
            };
            this.attached = true;
        }

        modifyLayout(layout) {
            if (!Array.isArray(layout) || this.view.scope !== 'Sms') {
                return false;
            }
            if (!layout.some(panel => panel.name === 'turboSmsTransport')) {
                layout.push({
                    name: 'turboSmsTransport',
                    label: 'turboSmsTransport',
                    rows: [
                        [{name: 'turboSmsSendType'}, {name: 'turboSmsDeliveryStatus'}],
                        [{name: 'turboSmsDeliveryData', fullWidth: true}, false]
                    ]
                });
            }
            return true;
        }

        activate() {
            if (!this.bound) {
                this.view.listenTo(this.view.model,
                    'change:turboSmsSendType change:turboSmsDeliveryData',
                    () => this.controlPanel()
                );
                this.bound = true;
            }
            this.controlPanel();
        }

        controlPanel() {
            const data = this.view.model.get('turboSmsDeliveryData');
            const visible = Boolean(this.view.model.get('turboSmsSendType')) ||
                Boolean(data && Array.isArray(data.recipients) && data.recipients.length);

            if (visible) {
                this.view.showPanel('turboSmsTransport');
            } else {
                this.view.hidePanel('turboSmsTransport', true);
            }
        }
    };
});
