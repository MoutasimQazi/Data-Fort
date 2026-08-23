/* settings.js — tenant policy.
 *
 * Every control here changes how much data is exposed, so nothing saves
 * silently: the server audits each field with its before and after
 * value, and warns on the two changes that weaken the product most.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var f = {
    defaultQuota:      document.getElementById('defQuota'),
    maxAssigned:       document.getElementById('maxAssign'),
    maskPhone:         document.getElementById('maskPhone'),
    maskEmail:         document.getElementById('maskEmail'),
    bakeWatermark:     document.getElementById('bakeWatermark'),
    honeytokensPerRep: document.getElementById('honeyRate'),
    burstAlertLimit:   document.getElementById('burstLimit')
  };

  function load() {
    API.settings().then(function (s) {
      f.defaultQuota.value      = s.defaultQuota;
      f.maxAssigned.value       = s.maxAssigned;
      f.maskPhone.checked       = s.maskPhone;
      f.maskEmail.checked       = s.maskEmail;
      f.bakeWatermark.checked   = s.bakeWatermark;
      f.honeytokensPerRep.value = s.honeytokensPerRep;
      f.burstAlertLimit.value   = s.burstAlertLimit;
    }).catch(D.fail);
  }

  document.getElementById('saveAll').addEventListener('click', function () {
    var btn = this;

    /* Turning masking off is not a preference, it is a change to the
     * product's central claim. Confirm it in words before sending. */
    if (!f.maskPhone.checked && !confirm(
      'Turn phone masking OFF?\n\n' +
      'Every rep will be able to read every assigned number without spending ' +
      'quota. The daily limit will no longer cap how much contact data anyone ' +
      'can collect.')) {
      f.maskPhone.checked = true;
      return;
    }

    btn.disabled = true;

    API.saveSettings({
      defaultQuota:      parseInt(f.defaultQuota.value, 10),
      maxAssigned:       parseInt(f.maxAssigned.value, 10),
      maskPhone:         f.maskPhone.checked,
      maskEmail:         f.maskEmail.checked,
      bakeWatermark:     f.bakeWatermark.checked,
      honeytokensPerRep: parseInt(f.honeytokensPerRep.value, 10),
      burstAlertLimit:   parseInt(f.burstAlertLimit.value, 10)
    }).then(function (res) {
      D.toast(res.changed ? 'Settings saved.' : 'No changes to save.', 'ok');
      if (res.warning) D.toast(res.warning, 'error', 9000);
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  /* Baking the watermark into revealed values costs accessibility — a
   * screen reader cannot read a number that is only pixels. Say so at
   * the moment someone turns it on, not in a footnote. */
  f.bakeWatermark.addEventListener('change', function () {
    if (this.checked) {
      D.toast('Revealed contacts will render as images. Screen readers cannot read them aloud.',
        null, 7000);
    }
  });

  D.ready(load);
})();
