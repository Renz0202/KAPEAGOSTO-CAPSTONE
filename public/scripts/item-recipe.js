(function () {
  'use strict';

  function inventorySize(value) {
    const size = String(value || '').trim();
    if (!size || size === '-') return '';
    if (/(?:^|\b)12\s*oz\b/i.test(size)) return '12oz Hot';
    if (/(?:^|\b)16\s*oz\b/i.test(size)) return '16oz';
    if (/(?:^|\b)22\s*oz\b/i.test(size)) return '22oz';
    return size;
  }

  document.querySelectorAll('[data-item-recipe]').forEach(function (section) {
    const form = section.closest('form');
    const sizeField = form?.querySelector('[name="cup_size"]');
    const nameField = form?.querySelector('[name="name"]');
    const scope = section.querySelector('[data-recipe-scope]');
    if (!sizeField) return;

    function updateRecipe() {
      const selectedSize = inventorySize(sizeField.value);
      const isHot = /\bcold\b/i.test(sizeField.value) ? false :
        /\bhot\b/i.test(sizeField.value) || /hot/i.test(nameField?.value || '');
      const packagingChoices = new Map();
      section.querySelectorAll('[data-recipe-row]').forEach(function (row) {
        const kind = row.dataset.recipeKind;
        const materialSize = inventorySize(row.dataset.recipeSize);
        const isPackaging = row.dataset.recipeType === 'packaging';
        const matchesSize = !materialSize || materialSize === selectedSize;
        const hasCupSize = !['cup', 'lid'].includes(kind) || Boolean(materialSize && selectedSize);
        const matchesTemperature = !(kind === 'straw' && isHot) && !(kind === 'stirrer' && !isHot);
        const matches = !isPackaging || (matchesSize && hasCupSize && matchesTemperature);
        row.hidden = !matches;
        const input = row.querySelector('input');
        if (input) {
          // Keep draft quantities when switching sizes, but never submit hidden packaging.
          input.disabled = !matches;
          input.placeholder = '0 ' + row.dataset.recipeUnit;
          if (matches && isPackaging && ['cup', 'lid', isHot ? 'stirrer' : 'straw'].includes(kind)) {
            if (!packagingChoices.has(kind)) packagingChoices.set(kind, []);
            const inventoryId = Number(input.dataset?.requirementId || input.name?.match(/\[(\d+)\]/)?.[1]);
            packagingChoices.get(kind).push({ row, input, inventoryId: inventoryId > 0 ? inventoryId : Number.MAX_SAFE_INTEGER });
          }
        }
      });
      packagingChoices.forEach(function (choices) {
        // An explicit wide straw replaces regular straws, just as it does on the server.
        if (choices.some(choice => Number(choice.input.value) > 0)) return;
        choices.sort((a, b) => a.inventoryId - b.inventoryId);
        const automatic = choices[0];
        automatic.input.placeholder = 'Auto: 1 ' + automatic.row.dataset.recipeUnit;
      });
      if (scope) {
        scope.textContent = 'Recipe for one ' + sizeField.value + ' serving. Only matching packaging is shown.';
      }
    }

    section.addEventListener('input', updateRecipe);
    sizeField.addEventListener('change', updateRecipe);
    nameField?.addEventListener('input', updateRecipe);
    updateRecipe();
  });
})();