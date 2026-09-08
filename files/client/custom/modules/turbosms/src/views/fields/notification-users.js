Espo.define('turbosms:views/fields/notification-users', 'views/fields/link-multiple', function (Dep) {
    return class extends Dep {
        createDisabled = true;

        getSelectFilters() {
            return {
                active: {type: 'equals', attribute: 'isActive', value: true},
                internal: {
                    type: 'in',
                    attribute: 'type',
                    value: ['regular', 'admin', 'super-admin'],
                },
            };
        }
    };
});
