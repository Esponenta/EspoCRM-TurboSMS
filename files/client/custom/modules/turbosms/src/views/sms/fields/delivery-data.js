Espo.define('turbosms:views/sms/fields/delivery-data', 'views/fields/base', function (Dep) {
    const channelList = ['sms', 'viber'];

    return class extends Dep {
        templateContent = '<div class="turbosms-delivery-data"></div>';

        afterRender() {
            this.renderRows();
        }

        renderRows() {
            const data = this.model.get(this.name) || {};
            const $container = this.$el.find('.turbosms-delivery-data').empty();
            const recipients = Array.isArray(data.recipients) ? data.recipients : [];
            if (!recipients.length) {
                $container.text(this.translate('None'));
                return;
            }
            const $table = $('<table>', {class: 'table table-bordered table-condensed'});
            const $head = $('<tr>');
            ['turboSmsRecipient', 'turboSmsChannel', 'turboSmsMessageId', 'turboSmsProviderStatus', 'turboSmsResponseCode', 'turboSmsProviderTime', 'turboSmsCost'].forEach(key => $('<th>').text(this.translate(key, 'fields', 'Sms')).appendTo($head));
            $('<thead>').append($head).appendTo($table);
            const $body = $('<tbody>').appendTo($table);
            this.getRows(recipients).forEach(values => {
                const $row = $('<tr>');
                values.forEach(value => $('<td>', {class: 'text-break'}).text(value).appendTo($row));
                $body.append($row);
            });
            $('<div>', {class: 'table-responsive'}).append($table).appendTo($container);
            if (data.trackingExpired) $('<div>', {class: 'text-muted'}).text(this.translate('turboSmsTrackingExpired', 'messages', 'Sms')).appendTo($container);
        }

        channelLabel(channel) {
            return this.translate(channel, 'options.turboSmsSendType', 'Sms');
        }

        getRows(recipients) {
            return recipients.flatMap(recipient => {
                const code = recipient.statusResponseCode ?? recipient.responseCode ?? '';
                const rows = channelList.flatMap(channel => {
                    const details = recipient[channel];
                    return details ? [[recipient.recipient || '', this.channelLabel(channel), recipient.messageId || '', this.statusLabel(details.status), code, details.updatedAt || details.sentAt || '', this.formatCost(details.cost)]] : [];
                });
                return rows.length || code === '' ? rows : [[recipient.recipient || '', '', '', this.statusLabel('Unconfirmed'), code, '', '']];
            });
        }

        statusLabel(status) {
            return this.getLanguage().has(status, 'options.turboSmsChannelStatus', 'Sms') ? this.translate(status, 'options.turboSmsChannelStatus', 'Sms') : String(status || this.translate('Unknown', 'options.turboSmsChannelStatus', 'Sms'));
        }

        formatCost(value) {
            return value === null || value === undefined || value === '' ? '' : Number(value).toLocaleString(this.getLanguage().name.replace('_', '-'), {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    };
});
