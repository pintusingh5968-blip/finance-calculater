document.addEventListener('DOMContentLoaded', () => {
  const menuButton = document.querySelector('[data-menu-toggle]');
  const sidebar = document.querySelector('#sidebar');
  if (menuButton && sidebar) {
    menuButton.addEventListener('click', () => sidebar.classList.toggle('sidebar-open'));
    document.addEventListener('click', (event) => {
      if (window.innerWidth <= 820 && sidebar.classList.contains('sidebar-open') && !sidebar.contains(event.target) && !menuButton.contains(event.target)) {
        sidebar.classList.remove('sidebar-open');
      }
    });
  }

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm || 'Continue?')) event.preventDefault();
    });
  });

  document.querySelectorAll('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => button.closest('[data-alert]')?.remove());
  });

  const typeSelect = document.querySelector('[data-kind-select]');
  const categorySelect = document.querySelector('[data-category-select]');
  if (typeSelect && categorySelect) {
    const syncCategories = () => {
      const kind = typeSelect.value;
      const options = [...categorySelect.options];
      let firstVisible = null;
      options.forEach((option) => {
        const visible = option.dataset.kind === kind;
        option.hidden = !visible;
        option.disabled = !visible;
        if (visible && firstVisible === null) firstVisible = option;
      });
      if (!categorySelect.selectedOptions.length || categorySelect.selectedOptions[0].disabled) {
        if (firstVisible) firstVisible.selected = true;
      }
    };
    typeSelect.addEventListener('change', syncCategories);
    syncCategories();
  }
});
