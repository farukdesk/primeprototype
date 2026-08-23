package bd.ac.primeuniversity.studentportal.ui.support

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.SupportTicket
import bd.ac.primeuniversity.studentportal.databinding.ActivitySupportTicketsBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * IT Support tickets. Lists the student's own tickets (from
 * admin/api/student/support-tickets.php) and lets them open a new one
 * or drill into the full ticket thread.
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

    /** Opens the full ticket thread (description, attachments and comments). */
    private fun showDetail(ticket: SupportTicket) {
        startActivity(
            Intent(this, SupportTicketDetailActivity::class.java)
                .putExtra(SupportTicketDetailActivity.EXTRA_TICKET_ID, ticket.id)
        )
    }
}
