package bd.ac.primeuniversity.studentportal.ui.staff.leave

import android.os.Bundle
import android.text.InputType
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.PendingApproval
import bd.ac.primeuniversity.studentportal.databinding.ActivityLeaveApprovalsBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemLeaveApprovalBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Leave Approvals: leave requests currently awaiting the signed-in employee's
 * decision. Only reachable for employees whose user group appears in an
 * active leave approval flow (me.permissions.can_approve_leaves).
 */
class LeaveApprovalsActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLeaveApprovalsBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private var hasSignature = true
    private val adapter = ApprovalAdapter(
        onApprove = { confirmDecision(it, approve = true) },
        onReject = { confirmDecision(it, approve = false) },
    )

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLeaveApprovalsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter
        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info, R.color.cat_campus
        )
        binding.swipeRefresh.setOnRefreshListener { load() }
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getLeaveApprovals()) {
                is AppResult.Success -> {
                    hasSignature = result.data.hasSignature
                    adapter.submit(result.data.requests)
                    if (result.data.requests.isEmpty()) {
                        binding.emptyState.setText(R.string.approvals_empty)
                        binding.emptyState.visibility = View.VISIBLE
                    }
                }
                is AppResult.Error -> {
                    adapter.submit(emptyList())
                    binding.emptyState.text = result.message
                    binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
        }
    }

    private fun confirmDecision(request: PendingApproval, approve: Boolean) {
        // Approving requires an uploaded signature (same rule as the web panel).
        if (approve && !hasSignature) {
            AlertDialog.Builder(this)
                .setTitle(R.string.approvals_signature_required_title)
                .setMessage(R.string.approvals_signature_required)
                .setPositiveButton(android.R.string.ok, null)
                .show()
            return
        }

        val noteInput = EditText(this).apply {
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_MULTI_LINE
            minLines = 2
            hint = getString(R.string.approvals_note_hint)
        }
        val container = FrameLayout(this).apply {
            val pad = (20 * resources.displayMetrics.density).toInt()
            setPadding(pad, pad / 2, pad, 0)
            addView(noteInput)
        }

        AlertDialog.Builder(this)
            .setTitle(if (approve) R.string.approvals_approve_title else R.string.approvals_reject_title)
            .setMessage(
                getString(
                    if (approve) R.string.approvals_approve_confirm else R.string.approvals_reject_confirm,
                    request.requesterName ?: getString(R.string.employee),
                )
            )
            .setView(container)
            .setPositiveButton(if (approve) R.string.approve else R.string.reject) { _, _ ->
                act(request, approve, noteInput.text?.toString()?.trim().orEmpty())
            }
            .setNegativeButton(R.string.cancel, null)
            .show()
    }

    private fun act(request: PendingApproval, approve: Boolean, note: String) {
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            when (val result = app.repository.actOnLeaveApproval(request.id, approve, note)) {
                is AppResult.Success -> {
                    Toast.makeText(
                        this@LeaveApprovalsActivity,
                        if (approve) R.string.approvals_done_approved else R.string.approvals_done_rejected,
                        Toast.LENGTH_LONG,
                    ).show()
                    load()
                }
                is AppResult.Error -> {
                    binding.progress.visibility = View.GONE
                    Toast.makeText(this@LeaveApprovalsActivity, result.message, Toast.LENGTH_LONG).show()
                }
            }
        }
    }

    private class ApprovalAdapter(
        private val onApprove: (PendingApproval) -> Unit,
        private val onReject: (PendingApproval) -> Unit,
    ) : RecyclerView.Adapter<ApprovalAdapter.VH>() {

        private val items = mutableListOf<PendingApproval>()

        fun submit(list: List<PendingApproval>) {
            items.clear()
            items.addAll(list)
            notifyDataSetChanged()
        }

        class VH(val b: ItemLeaveApprovalBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
            VH(ItemLeaveApprovalBinding.inflate(LayoutInflater.from(parent.context), parent, false))

        override fun getItemCount(): Int = items.size

        override fun onBindViewHolder(holder: VH, position: Int) {
            val r = items[position]

            holder.b.requester.text = r.requesterName.orEmpty()
            val meta = listOfNotNull(
                r.designation?.takeIf { it.isNotBlank() },
                r.department?.takeIf { it.isNotBlank() },
            ).joinToString(" \u00b7 ")
            holder.b.requesterMeta.text = meta
            holder.b.requesterMeta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE

            holder.b.category.text = r.categoryLabel ?: r.category

            holder.b.dates.text = if (r.category == "short") {
                "${r.startDate} \u00b7 ${r.startTime ?: ""}\u2013${r.endTime ?: ""}"
            } else {
                "${r.startDate} \u2192 ${r.endDate} \u00b7 ${fmtDays(r.days)} day(s)"
            }

            holder.b.reason.text = r.reason.orEmpty()
            holder.b.reason.visibility =
                if (r.reason.isNullOrBlank()) View.GONE else View.VISIBLE

            val step = r.stepLabel
            if (step.isNullOrBlank()) {
                holder.b.stepLabel.visibility = View.GONE
            } else {
                holder.b.stepLabel.visibility = View.VISIBLE
                holder.b.stepLabel.text =
                    holder.b.root.context.getString(R.string.approvals_step, step)
            }

            holder.b.btnApprove.setOnClickListener { onApprove(r) }
            holder.b.btnReject.setOnClickListener { onReject(r) }
        }

        private fun fmtDays(v: Double): String =
            if (v % 1.0 == 0.0) v.toInt().toString() else v.toString()
    }
}
