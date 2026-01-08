

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



// CUSTOMER DASHBOARD DEMO ONLY   !!!!
// document.addEventListener("DOMContentLoaded", () => {
//   document.getElementById("customerName").textContent = "Trisha Mae";
//   document.getElementById("queueNo").textContent = "#P-001";
//   document.getElementById("queueStatus").textContent = "PENDING";
//   document.getElementById("queueService").textContent = "Service: Document Printing";
//   document.getElementById("queueDetails").textContent = "Short Bond Paper - Black & White";
//   document.getElementById("ongoingCount").textContent = "04";
// });


document.querySelector(".quick-card").addEventListener("click", () => {
  window.location.href = "queue.html";
});


