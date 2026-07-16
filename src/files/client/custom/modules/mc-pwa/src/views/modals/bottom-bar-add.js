/**
 * MC PWA – "add bottom bar button" modal.
 * The core tab-list add modal without the Divider button.
 */
define(['views/settings/modals/tab-list-field-add'], (Dep) => {

    return class extends Dep {

        setup() {
            super.setup();

            this.buttonList = this.buttonList.filter(item => {
                return item.name !== 'addDivider';
            });
        }
    };
});
