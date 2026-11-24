const password = document.querySelector('#password');
const toggle = document.querySelector('.visibility');

toggle.hidden = false;
password.classList.add('has-toggle');

toggle.addEventListener('click', () => {
    const showPassword = password.type === 'password';
    password.type = showPassword ? 'text' : 'password';
    toggle.textContent = showPassword ? 'Hide' : 'Show';
    toggle.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
});
