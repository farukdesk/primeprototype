package bd.ac.primeuniversity.studentportal.ui.staff.invigilation

import android.content.res.ColorStateList
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.InvigilationDuty
import bd.ac.primeuniversity.studentportal.data.model.InvigilationDutyResponse
import bd.ac.primeuniversity.studentportal.data.model.InvigilationSwapRequest
import bd.ac.primeuniversity.studentportal.data.model.InvigilationTarget
import bd.ac.primeuniversity.studentportal.databinding.ActivityInvigilationDutyBinding
import bd.ac.primeuniversity.studentportal.databinding.DialogInvigilationRequestBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemInvigilationDutyBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemInvigilationRequestBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemInvigilationSectionBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Faculty: the signed-in employee's invigilation duties, split into Upcoming
 * and Past sections with summary cards (Total / Upcoming / Attended). Mirrors
 * the web self-service page admin/invigilation-duty/index.php, including the
 * swap / substitute workflow (request, respond, cancel).
 */
class InvigilationDutyActivity : AppCompatActivity() {

    private lateinit var binding: ActivityInvigilationDutyBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = DutyAdapter(
        onRequestSwap = { duty -> showRequestDialog(duty) },
        onAccept = { req -> actOnRequest(req.id, "accept") },
        onReject = { req -> actOnRequest(req.id, "reject") },
        onCancel = { req -> actOnRequest(req.id, "cancel") },
    )

    /** Eligible target faculty per upcoming slot id (from the latest load). */
    private var eligibleTargets: Map<String, List<InvigilationTarget>> = emptyMap()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityInvigilationDutyBinding.inflate(layoutInflater)
        setContentView(binding.root)

        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { _, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            binding.root.updatePadding(top = bars.top, bottom = bars.bottom)
            insets
        }

        binding.btnBack.setOnClickListener { finish() }
        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE
        binding.summaryRow.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getInvigilationDuties()) {
                is AppResult.Success -> render(result.data)
                is AppResult.Error -> {
                    adapter.submit(emptyList())
                    binding.emptyState.text = result.message
                    binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun render(data: InvigilationDutyResponse) {
        if (!data.hasProfile) {
            eligibleTargets = emptyMap()
            adapter.submit(emptyList())
            binding.emptyState.setText(R.string.invigilation_no_profile)
            binding.emptyState.visibility = View.VISIBLE
            return
        }

        eligibleTargets = data.eligibleTargets

        val s = data.summary
        binding.summaryRow.visibility = View.VISIBLE
        binding.totalValue.text = (s?.total ?: 0).toString()
        binding.upcomingValue.text = (s?.upcoming ?: 0).toString()
        binding.attendedValue.text = (s?.attended ?: 0).toString()

        val rows = buildList {
            if (data.incoming.isNotEmpty()) {
                add(Row.Section(getString(R.string.invigilation_section_incoming)))
                data.incoming.forEach { add(Row.Incoming(it)) }
            }

            add(Row.Section(getString(R.string.invigilation_section_upcoming)))
            if (data.upcoming.isEmpty()) {
                add(Row.Empty(getString(R.string.invigilation_none_upcoming)))
            } else {
                data.upcoming.forEach { add(Row.Duty(it, canRequest = true)) }
            }

            if (data.outgoing.isNotEmpty()) {
                add(Row.Section(getString(R.string.invigilation_section_my_requests)))
                data.outgoing.forEach { add(Row.Outgoing(it)) }
            }

            add(Row.Section(getString(R.string.invigilation_section_past)))
            if (data.past.isEmpty()) {
                add(Row.Empty(getString(R.string.invigilation_none_past)))
            } else {
                data.past.forEach { add(Row.Duty(it, canRequest = false)) }
            }
        }
        adapter.submit(rows)
    }

    private fun showRequestDialog(duty: InvigilationDuty) {
        val targets = eligibleTargets[duty.id.toString()].orEmpty()
        val dialogBinding = DialogInvigilationRequestBinding.inflate(layoutInflater)

        dialogBinding.slotLabel.text = buildString {
            append(duty.dateLabel)
            append(" · ").append(duty.timeSlot)
            append(" · ").append(getString(R.string.invigilation_room_fmt, duty.roomNumber))
        }

        // Request type: 0 = substitute, 1 = swap (matches the web order).
        val typeLabels = listOf(
            getString(R.string.invigilation_type_substitute),
            getString(R.string.invigilation_type_swap),
        )
        dialogBinding.spRequestType.adapter = ArrayAdapter(
            this, android.R.layout.simple_spinner_dropdown_item, typeLabels
        )

        if (targets.isEmpty()) {
            dialogBinding.spTarget.adapter = ArrayAdapter(
                this, android.R.layout.simple_spinner_dropdown_item,
                listOf(getString(R.string.invigilation_no_targets))
            )
            dialogBinding.spTarget.isEnabled = false
        } else {
            dialogBinding.spTarget.adapter = ArrayAdapter(
                this, android.R.layout.simple_spinner_dropdown_item, targets.map { it.label }
            )
        }

        val dialog = AlertDialog.Builder(this)
            .setView(dialogBinding.root)
            .setNegativeButton(android.R.string.cancel, null)
            .setPositiveButton(R.string.invigilation_send_request, null)
            .create()

        dialog.setOnShowListener {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
                if (targets.isEmpty()) {
                    toast(getString(R.string.invigilation_no_targets)); return@setOnClickListener
                }
                val target = targets.getOrNull(dialogBinding.spTarget.selectedItemPosition)
                if (target == null) {
                    toast(getString(R.string.invigilation_request_err_target)); return@setOnClickListener
                }
                val requestType = if (dialogBinding.spRequestType.selectedItemPosition == 1) "swap" else "substitute"
                val message = dialogBinding.message.text.toString().trim()
                dialog.dismiss()
                sendRequest(duty.id, requestType, target.id, message)
            }
        }
        dialog.show()
    }

    private fun sendRequest(slotId: Int, requestType: String, targetFacultyId: Int, message: String) {
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            val result = app.repository.requestInvigilationSwap(slotId, requestType, targetFacultyId, message)
            binding.progress.visibility = View.GONE
            when (result) {
                is AppResult.Success -> {
                    toast(result.data.message ?: getString(R.string.invigilation_send_request))
                    load()
                }
                is AppResult.Error -> toast(result.message)
            }
        }
    }

    private fun actOnRequest(requestId: Int, action: String) {
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            val result = app.repository.actOnInvigilationRequest(requestId, action)
            binding.progress.visibility = View.GONE
            when (result) {
                is AppResult.Success -> {
                    result.data.message?.let { toast(it) }
                    load()
                }
                is AppResult.Error -> toast(result.message)
            }
        }
    }

    private fun toast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show()
    }

    private sealed interface Row {
        data class Section(val title: String) : Row
        data class Duty(val duty: InvigilationDuty, val canRequest: Boolean) : Row
        data class Incoming(val request: InvigilationSwapRequest) : Row
        data class Outgoing(val request: InvigilationSwapRequest) : Row
        data class Empty(val text: String) : Row
    }

    private class DutyAdapter(
        private val onRequestSwap: (InvigilationDuty) -> Unit,
        private val onAccept: (InvigilationSwapRequest) -> Unit,
        private val onReject: (InvigilationSwapRequest) -> Unit,
        private val onCancel: (InvigilationSwapRequest) -> Unit,
    ) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

        private val items = mutableListOf<Row>()

        fun submit(list: List<Row>) {
            items.clear()
            items.addAll(list)
            notifyDataSetChanged()
        }

        override fun getItemCount(): Int = items.size

        override fun getItemViewType(position: Int): Int = when (items[position]) {
            is Row.Section -> TYPE_SECTION
            is Row.Duty -> TYPE_DUTY
            is Row.Incoming -> TYPE_REQUEST
            is Row.Outgoing -> TYPE_REQUEST
            is Row.Empty -> TYPE_EMPTY
        }

        class SectionVH(val b: ItemInvigilationSectionBinding) : RecyclerView.ViewHolder(b.root)
        class DutyVH(val b: ItemInvigilationDutyBinding) : RecyclerView.ViewHolder(b.root)
        class RequestVH(val b: ItemInvigilationRequestBinding) : RecyclerView.ViewHolder(b.root)
        class EmptyVH(val b: ItemInvigilationSectionBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
            val inflater = LayoutInflater.from(parent.context)
            return when (viewType) {
                TYPE_DUTY -> DutyVH(ItemInvigilationDutyBinding.inflate(inflater, parent, false))
                TYPE_REQUEST -> RequestVH(ItemInvigilationRequestBinding.inflate(inflater, parent, false))
                TYPE_EMPTY -> EmptyVH(ItemInvigilationSectionBinding.inflate(inflater, parent, false))
                else -> SectionVH(ItemInvigilationSectionBinding.inflate(inflater, parent, false))
            }
        }

        override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
            when (val row = items[position]) {
                is Row.Section -> (holder as SectionVH).b.sectionTitle.text = row.title
                is Row.Empty -> (holder as EmptyVH).b.sectionTitle.apply {
                    text = row.text
                    setTextColor(ContextCompat.getColor(context, android.R.color.darker_gray))
                }
                is Row.Duty -> bindDuty((holder as DutyVH).b, row.duty, row.canRequest)
                is Row.Incoming -> bindIncoming((holder as RequestVH).b, row.request)
                is Row.Outgoing -> bindOutgoing((holder as RequestVH).b, row.request)
            }
        }

        private fun bindDuty(b: ItemInvigilationDutyBinding, d: InvigilationDuty, canRequest: Boolean) {
            val ctx = b.root.context
            b.dateLabel.text = d.dateLabel
            b.weekday.text = d.weekday
            b.exam.text = buildString {
                append(d.examName)
                if (!d.examYear.isNullOrBlank()) append(" (").append(d.examYear).append(")")
            }
            b.time.text = d.timeSlot
            b.room.text = ctx.getString(R.string.invigilation_room_fmt, d.roomNumber)

            b.role.text = ctx.getString(R.string.invigilation_role_fmt, d.myPosition)
            b.role.backgroundTintList = ColorStateList.valueOf(
                ContextCompat.getColor(ctx, if (d.myPosition == 1) R.color.primary else R.color.info)
            )

            val dept = d.deptName?.takeIf { it.isNotBlank() }
            if (dept != null) {
                b.department.text = ctx.getString(R.string.invigilation_dept_fmt, dept)
                b.department.visibility = View.VISIBLE
            } else {
                b.department.visibility = View.GONE
            }

            b.coInvigilator.text = ctx.getString(
                R.string.invigilation_co_fmt,
                d.coName?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.dash),
            )

            val (labelRes, colorRes) = when (d.attended) {
                1 -> R.string.invigilation_attended_badge to R.color.success
                0 -> R.string.invigilation_absent_badge to R.color.error
                else -> R.string.invigilation_pending_badge to R.color.info
            }
            b.attendance.setText(labelRes)
            b.attendance.backgroundTintList =
                ColorStateList.valueOf(ContextCompat.getColor(ctx, colorRes))

            if (canRequest) {
                b.btnSwap.visibility = View.VISIBLE
                b.btnSwap.setOnClickListener { onRequestSwap(d) }
            } else {
                b.btnSwap.visibility = View.GONE
                b.btnSwap.setOnClickListener(null)
            }
        }

        private fun bindRequestCommon(b: ItemInvigilationRequestBinding, r: InvigilationSwapRequest) {
            val ctx = b.root.context
            b.exam.text = r.examName
            b.duty.text = buildString {
                append(r.dateLabel)
                append(" · ").append(r.timeSlot)
                append(" · ").append(ctx.getString(R.string.invigilation_room_fmt, r.roomNumber))
            }
            val isSwap = r.requestType == "swap"
            b.typeBadge.setText(if (isSwap) R.string.invigilation_badge_swap else R.string.invigilation_badge_substitute)
            b.typeBadge.backgroundTintList = ColorStateList.valueOf(
                ContextCompat.getColor(ctx, if (isSwap) R.color.primary else R.color.info)
            )
            val msg = r.message?.takeIf { it.isNotBlank() }
            if (msg != null) {
                b.message.text = msg
                b.message.visibility = View.VISIBLE
            } else {
                b.message.visibility = View.GONE
            }
        }

        private fun bindIncoming(b: ItemInvigilationRequestBinding, r: InvigilationSwapRequest) {
            val ctx = b.root.context
            bindRequestCommon(b, r)
            b.person.text = ctx.getString(
                R.string.invigilation_from_fmt,
                r.requesterName?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.dash),
            )
            b.statusBadge.visibility = View.GONE
            b.actionRow.visibility = View.VISIBLE

            b.btnPrimary.visibility = View.VISIBLE
            b.btnPrimary.setText(R.string.invigilation_accept)
            b.btnPrimary.backgroundTintList =
                ColorStateList.valueOf(ContextCompat.getColor(ctx, R.color.success))
            b.btnPrimary.setOnClickListener { onAccept(r) }

            b.btnSecondary.visibility = View.VISIBLE
            b.btnSecondary.setText(R.string.invigilation_decline)
            b.btnSecondary.setOnClickListener { onReject(r) }
        }

        private fun bindOutgoing(b: ItemInvigilationRequestBinding, r: InvigilationSwapRequest) {
            val ctx = b.root.context
            bindRequestCommon(b, r)
            b.person.text = ctx.getString(
                R.string.invigilation_to_fmt,
                r.targetName?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.dash),
            )

            val (labelRes, colorRes) = when (r.status) {
                "accepted" -> R.string.invigilation_attended_badge to R.color.success
                "rejected" -> R.string.invigilation_decline to R.color.error
                "cancelled" -> R.string.invigilation_cancel_request to android.R.color.darker_gray
                else -> R.string.invigilation_pending_badge to R.color.warning
            }
            b.statusBadge.visibility = View.VISIBLE
            b.statusBadge.setText(labelRes)
            b.statusBadge.backgroundTintList =
                ColorStateList.valueOf(ContextCompat.getColor(ctx, colorRes))

            b.btnPrimary.visibility = View.GONE
            b.btnPrimary.setOnClickListener(null)
            if (r.status == "pending") {
                b.actionRow.visibility = View.VISIBLE
                b.btnSecondary.visibility = View.VISIBLE
                b.btnSecondary.setText(R.string.invigilation_cancel_request)
                b.btnSecondary.setOnClickListener { onCancel(r) }
            } else {
                b.actionRow.visibility = View.GONE
                b.btnSecondary.setOnClickListener(null)
            }
        }

        companion object {
            private const val TYPE_SECTION = 0
            private const val TYPE_DUTY = 1
            private const val TYPE_EMPTY = 2
            private const val TYPE_REQUEST = 3
        }
    }
}
