document.addEventListener('DOMContentLoaded', function () {
    // عنصر البطاقة
    const flipCard = document.getElementById('flipCard');

    // النقر على البطاقة لقلبها (مع استثناء النقر على العناصر الداخلية التي تمنع الانتشار)
    if (flipCard) {
        flipCard.addEventListener('click', function (e) {
            // إذا كان المستخدم ضغط على حقل إدخال أو زر، لا نقلب
            if (
                e.target.tagName === 'INPUT' ||
                e.target.tagName === 'BUTTON' ||
                e.target.closest('.password-toggle') ||
                e.target.closest('.flip-hint')
            ) {
                return;
            }
            // وإلا قلب البطاقة
            flipCard.classList.toggle('flipped');
        });
    }

    // إظهار/إخفاء كلمة المرور
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('passwordInput');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function (e) {
            e.stopPropagation(); // منع وصول النقر إلى flipCard
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);

            const icon = this.querySelector('i');
            if (!icon) return;
            if (type === 'password') {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        });
    }

    // تأثيرات عند التركيز على الحقول
    const inputs = document.querySelectorAll('.form-control');
    inputs.forEach((input) => {
        input.addEventListener('focus', function () {
            const icon = this.parentElement.querySelector('.input-icon');
            if (icon) icon.style.color = '#ffffff';
        });

        input.addEventListener('blur', function () {
            const icon = this.parentElement.querySelector('.input-icon');
            if (icon) icon.style.color = 'rgba(124, 21, 21, 0.8)';
        });
    });
});

