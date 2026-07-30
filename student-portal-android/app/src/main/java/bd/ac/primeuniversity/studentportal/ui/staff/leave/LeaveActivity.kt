package bd.ac.primeuniversity.studentportal.ui.staff.leave

import android.content.Intent
import android.content.res.ColorStateList
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.LeaveRequest
import bd.ac.primeuniversity.studentportal.databinding.ActivityLeaveBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemLeaveRequestBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Leave Management: yearly Casual / Sick balance, the employee's request
 * history with approval progress, and an Apply button.
 */
class LeaveActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLeaveBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = LeaveAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLeaveBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter
        binding.btnApply.setOnClickListener {
            startActivity(Intent(this, LeaveApplyActivity::class.java))
        }
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getStaffLeaves()) {
                is AppResult.Success -> {
                    val b = result.data.balance
                    binding.casualValue.text = if (b != null)
                        "${fmt(b.casualRemaining)} / ${fmt(b.casualTotal)}" else getString(R.string.dash)
                    binding.sickValue.text = if (b != null)
                        "${fmt(b.sickRemaining)} / ${fmt(b.sickTotal)}" else getString(R.string.dash)

                    adapter.submit(result.data.requests)
                    if (result.data.requests.isEmpty()) {
                        binding.emptyState.setText(R.string.leave_empty)
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
        }
    }

    private fun fmt(v: Double): String =
        if (v % 1.0 == 0.0) v.toInt().toString() else v.toString()

    private class LeaveAdapter : RecyclerView.Adapter<LeaveAdapter.VH>() {

        private val items = mutableListOf<LeaveRequest>()

        fun submit(list: List<LeaveRequest>) {
            items.clear()
            items.addAll(list)
            notifyDataSetChanged()
        }

        class VH(val b: ItemLeaveRequestBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
            VH(ItemLeaveRequestBinding.inflate(LayoutInflater.from(parent.context), parent, false))

        override fun getItemCount(): Int = items.size

        override fun onBindViewHolder(holder: VH, position: Int) {
            val r = items[position]
            val ctx = holder.b.root.context

            holder.b.category.text = r.categoryLabel ?: r.category
            holder.b.status.text = r.status.replaceFirstChar { it.uppercase() }
            holder.b.status.backgroundTintList = ColorStateList.valueOf(
                ContextCompat.getColor(ctx, statusColor(r.status))
            )

            holder.b.dates.text = if (r.category == "short") {
                "${r.startDate} \u00b7 ${r.startTime ?: ""}\u2013${r.endTime ?: ""}"
            } else {
                "${r.startDate} \u2192 ${r.endDate} \u00b7 ${fmtDays(r.days)} day(s)"
            }

            holder.b.reason.text = r.reason.orEmpty()
            holder.b.reason.visibility =
                if (r.reason.isNullOrBlank()) View.GONE else View.VISIBLE

            holder.b.approvals.text = approvalsText(r)
        }

        private fun approvalsText(r: LeaveRequest): String = when (r.status) {
            "pending" -> {
                val total = r.approvals.size
                if (total > 0) {
                    val current = r.approvals.firstOrNull { it.stepOrder == r.currentStep }
                    "Step ${r.currentStep} of $total \u00b7 Awaiting ${current?.label ?: "approval"}"
                } else {
                    "Awaiting approval flow setup"
                }
            }
            "approved" -> "Approved" + (r.approvals.lastOrNull { it.status == "approved" }
                ?.approverName?.let { " by $it" } ?: "")
            "rejected" -> "Rejected" + (r.approvals.firstOrNull { it.status == "rejected" }
                ?.note?.takeIf { it.isNotBlank() }?.let { ": $it" } ?: "")
            else -> r.status.replaceFirstChar { it.uppercase() }
        }

        private fun fmtDays(v: Double): String =
            if (v % 1.0 == 0.0) v.toInt().toString() else v.toString()

        private fun statusColor(status: String): Int = when (status) {
            "approved" -> R.color.success
            "rejected", "cancelled" -> R.color.error
            else -> R.color.info // pending
        }
    }
}
