package bd.ac.primeuniversity.studentportal.ui.support

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.text.HtmlCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.SupportTicket
import bd.ac.primeuniversity.studentportal.databinding.ActivitySupportTicketsBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.launch

/**
 * IT Support tickets. Lists the student's own tickets (from
 * admin/api/student/support-tickets.php) and lets them open a new one.
 */
class SupportTicketsActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySupportTicketsBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = SupportTicketAdapter { showDetail(it) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySupportTicketsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info
        )
        binding.swipeRefresh.setOnRefreshListener { load() }

        binding.btnNew.setOnClickListener {
            startActivity(Intent(this, SupportTicketCreateActivity::class.java))
        }
    }

    override fun onResume() {
        super.onResume()
        // Also refreshes the list after a new ticket is created.
        load(initial = adapter.itemCount == 0)
    }

    private fun load(initial: Boolean = false) {
        if (initial) binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getSupportTickets()) {
                is AppResult.Success -> {
                    adapter.submitList(result.data.tickets)
                    binding.emptyState.visibility =
                        if (result.data.tickets.isEmpty()) View.VISIBLE else View.GONE
                }
                is AppResult.Error -> {
                    Toast.makeText(this@SupportTicketsActivity, result.message, Toast.LENGTH_LONG)
                        .show()
                    if (adapter.itemCount == 0) binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
        }
    }

    /** Full ticket in a dialog: status, priority, category, dates and description. */
    private fun showDetail(ticket: SupportTicket) {
        val details = buildString {
            append(getString(R.string.support_detail_status, ticket.status, ticket.priority))
            append('\n')
            append(getString(R.string.support_detail_category, ticket.category))
            append('\n')
            append(getString(R.string.support_detail_created, ticket.date))
            ticket.deadline?.let {
                append('\n')
                append(getString(R.string.support_deadline, it))
            }
            append("\n\n")
            append(
                HtmlCompat.fromHtml(ticket.description, HtmlCompat.FROM_HTML_MODE_LEGACY)
                    .toString().trim()
            )
        }
        MaterialAlertDialogBuilder(this)
            .setTitle("${ticket.ticketNumber} \u00b7 ${ticket.title}")
            .setMessage(details)
            .setPositiveButton(R.string.close, null)
            .show()
    }
}
