// PASSWORD TOGGLE JS
document.addEventListener("DOMContentLoaded", () => {
  const togglePassword = document.getElementById("togglePassword");
  const password = document.getElementById("password");

  if (togglePassword && password) {
    togglePassword.addEventListener("click", () => {
      if (password.type === "password") {
        password.type = "text";
        togglePassword.textContent = "Hide";
      } else {
        password.type = "password";
        togglePassword.textContent = "Show";
      }
    });
  }
});


/* ==============================
   LANDING PAGE – SERVICE SCROLL
   ============================== */

function scrollToSection(id) {
  const section = document.getElementById(id);
  if (section) {
    section.scrollIntoView({
      behavior: 'smooth'
    });
  }
}



/* LANDING PAGE – DOCUMENT PRINTING MODAL */

function openModal(id) {
  document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

document.querySelectorAll('.modal-overlay').forEach(modal => {
  modal.addEventListener('click', e => {
    if (e.target === modal) {
      modal.style.display = 'none';
    }
  });
});
