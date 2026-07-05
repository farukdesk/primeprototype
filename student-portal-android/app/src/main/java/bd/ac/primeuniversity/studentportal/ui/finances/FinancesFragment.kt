package bd.ac.primeuniversity.studentportal.ui.finances

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.FinanceSummary
import bd.ac.primeuniversity.studentportal.data.model.Payment
import bd.ac.primeuniversity.studentportal.data.model.ScheduleRow
import bd.ac.primeuniversity.studentportal.data.model.ScheduleSection
import bd.ac.primeuniversity.studentportal.databinding.FragmentFinancesBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemPaymentBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemScheduleRowBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemScheduleSectionBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import bd.ac.primeuniversity.studentportal.util.Formatters
import kotlinx.coroutines.launch

/** Finances tab: fee summary, fee schedule & outstanding balance breakdown and payment history. */
class FinancesFragment : Fragment() {

    private var _binding: FragmentFinancesBinding? = null
    private val binding get() = _binding!!
    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentFinancesBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.swipeRefresh.setOnRefreshListener { load(fromSwipe = true) }
        load()
    }

    private fun load(fromSwipe: Boolean = false) {
        if (!fromSwipe) binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            val result = app.repository.getFinances()
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
            when (result) {
                is AppResult.Success -> render(
                    result.data.summary, result.data.schedule, result.data.payments, result.data.message
                )
                is AppResult.Error -> showMessage(result.message)
            }
        }
    }

    private fun render(
        summary: FinanceSummary?,
        schedule: List<ScheduleSection>,
        payments: List<Payment>,
        message: String?
    ) {
        binding.semesterContainer.removeAllViews()
        binding.paymentContainer.removeAllViews()

        if (summary == null) {
            showMessage(message ?: getString(R.string.no_fee_package))
            return
        }
        binding.messageBox.visibility = View.GONE

        // Summary card
        binding.summaryCard.visibility = View.VISIBLE
        binding.totalDue.text = Formatters.money(summary.totalDue)
        binding.totalPaid.text = Formatters.money(summary.totalPaid)
        binding.outstanding.text = Formatters.money(summary.outstanding)
        binding.outstanding.setTextColor(
            if (summary.outstanding > 0) 0xFFFF6B6B.toInt() else 0xFF6EE7B7.toInt()
        )

        // Balance due right now (obligations up to the current month)
        binding.dueToday.text = Formatters.money(summary.dueAsOfToday)
        binding.dueTodayLabel.text = summary.asOfDate?.let {
            getString(R.string.due_as_of_today) + " (" + it + ")"
        } ?: getString(R.string.due_as_of_today)

        // Fee schedule & outstanding balance breakdown
        if (schedule.isNotEmpty()) {
            binding.semesterHeader.visibility = View.VISIBLE
            schedule.forEach { addScheduleSection(it) }
        } else {
            binding.semesterHeader.visibility = View.GONE
        }

        // Payment history
        if (payments.isNotEmpty()) {
            binding.paymentHeader.visibility = View.VISIBLE
            payments.forEach { addPaymentCard(it) }
        } else {
            binding.paymentHeader.visibility = View.GONE
        }
    }

    private fun addScheduleSection(section: ScheduleSection) {
        val item = ItemScheduleSectionBinding.inflate(
            layoutInflater, binding.semesterContainer, false
        )
        item.sectionTitle.text = section.title

        val outstanding = section.rows.sumOf { it.out }
        val cleared = outstanding <= 0.0
        item.sectionStatus.text = if (cleared) getString(R.string.cleared)
        else "${Formatters.money(outstanding)} due"
        item.sectionStatus.setTextColor(
            ContextCompat.getColor(requireContext(), if (cleared) R.color.success else R.color.error)
        )

        section.rows.forEach { row -> addScheduleRow(item, row) }
        binding.semesterContainer.addView(item.root)
    }

    private fun addScheduleRow(section: ItemScheduleSectionBinding, row: ScheduleRow) {
        val rowBinding = ItemScheduleRowBinding.inflate(
            layoutInflater, section.rowContainer, false
        )
        rowBinding.rowLabel.text = row.label
        rowBinding.rowDue.text = if (row.due > 0) Formatters.money(row.due) else "—"
        rowBinding.rowPaid.text = if (row.paid > 0) Formatters.money(row.paid) else "—"
        val cleared = row.out <= 0.0
        rowBinding.rowOut.text = when {
            row.out > 0 -> Formatters.money(row.out)
            row.due > 0 -> getString(R.string.paid)
            else -> "—"
        }
        rowBinding.rowOut.setTextColor(
            ContextCompat.getColor(requireContext(), if (cleared) R.color.success else R.color.error)
        )
        section.rowContainer.addView(rowBinding.root)
    }

    private fun addPaymentCard(payment: Payment) {
        val item = ItemPaymentBinding.inflate(
            layoutInflater, binding.paymentContainer, false
        )
        item.payType.text = payment.feeType ?: getString(R.string.fee_payment)
        val meta = buildList {
            payment.voucherNumber?.let { add("V#$it") }
            payment.date?.let { add(it) }
            add(payment.method)
        }.joinToString(" · ")
        item.payMeta.text = meta
        item.payAmount.text = Formatters.money(payment.amount)
        binding.paymentContainer.addView(item.root)
    }

    private fun showMessage(message: String) {
        binding.summaryCard.visibility = View.GONE
        binding.semesterHeader.visibility = View.GONE
        binding.paymentHeader.visibility = View.GONE
        binding.messageBox.visibility = View.VISIBLE
        binding.messageText.text = message
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
