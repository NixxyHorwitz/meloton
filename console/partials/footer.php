  </div><!-- /c-content -->
</div><!-- /c-main -->

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    $('.c-table').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
        pageLength: 20,
        lengthMenu: [[20, 50, 100, -1], [20, 50, 100, "Semua"]],
        autoWidth: false
    });
});
</script>
<script>
const sidebar  = document.getElementById('sidebar');
const backdrop = document.getElementById('backdrop');

function toggleSidebar() {
  sidebar.classList.toggle('open');
  backdrop.classList.toggle('active');
}
function closeSidebar() {
  sidebar.classList.remove('open');
  backdrop.classList.remove('active');
}

// Clock
function tick() {
  const now = new Date();
  const el  = document.getElementById('c-clock');
  if (el) el.textContent = now.toLocaleString('id-ID', {weekday:'short',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'});
}
tick(); setInterval(tick, 30000);
</script>
</body>
</html>
