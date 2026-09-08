  </div><!-- /c-content -->
</div><!-- /c-main -->

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Dynamic responsive table wrapper
    $('.c-table').each(function() {
        if (!$(this).parent().hasClass('table-responsive')) {
            $(this).wrap('<div class="table-responsive"></div>');
        }
    });

    // Safe DataTables initialization
    $('.c-table:not(.no-datatable):not(.no-dt)').each(function() {
        if (!$.fn.dataTable.isDataTable(this)) {
            $(this).DataTable({
                language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
                pageLength: 20,
                lengthMenu: [[20, 50, 100, -1], [20, 50, 100, "Semua"]],
                autoWidth: false,
                responsive: false
            });
        }
    });

    // Auto-adjust columns when Bootstrap tabs switch
    $('button[data-bs-toggle="tab"], a[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
        if ($.fn.dataTable) {
            $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
        }
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
