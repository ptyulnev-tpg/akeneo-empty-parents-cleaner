define([
  'jquery',
  'underscore',
  'pim/router',
  'pim/dashboard/abstract-widget',
  'acme/product-cleanup/template/empty-product-models-widget',
], function ($, _, router, AbstractWidget, template) {
  'use strict';

  return AbstractWidget.extend({
    template: _.template(template),

    events: {
      'click .acme-show-page': 'showPage',
    },

    showPage: function (event) {
      event.preventDefault();

      router.redirectToRoute('acme_empty_product_models_index');
    },
  });
});
