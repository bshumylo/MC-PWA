define(['action-handler'], (Dep) => {

    return class extends Dep {

        pushSettings() {
            this.view.createView('pwaPushSettings', 'mc-pwa:views/modals/push-settings')
                .then(view => view.render());
        }
    };
});
