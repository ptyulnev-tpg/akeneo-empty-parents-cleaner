define(['pim/controller/front', 'pim/form-builder'], function (BaseController, FormBuilder) {
  'use strict';

  return BaseController.extend({
    renderForm: function () {
      return FormBuilder.build('acme-empty-product-models-index').then(form => {
        form.setElement(this.$el).render();

        return form;
      });
    },
  });
});
