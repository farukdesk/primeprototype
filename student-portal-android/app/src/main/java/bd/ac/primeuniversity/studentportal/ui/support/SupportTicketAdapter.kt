package bd.ac.primeuniversity.studentportal.ui.support

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.SupportTicket
import bd.ac.primeuniversity.studentportal.databinding.ItemSupportTicketBinding

/** List adapter for the student's IT support tickets. */
class SupportTicketAdapter(
    private val onClick: (SupportTicket) -> Unit,
) : ListAdapter<SupportTicket, SupportTicketAdapter.VH>(DIFF) {

    inner class VH(val binding: ItemSupportTicketBinding) : RecyclerView.ViewHolder(binding.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH {
        val binding = ItemSupportTicketBinding.inflate(
            LayoutInflater.from(parent.context), parent, false
        )
        return VH(binding)
    }

    override fun onBindViewHolder(holder: VH, position: Int) {
        val item = getItem(position)
        with(holder.binding) {
            date.text = "${item.ticketNumber} \u00b7 ${item.date}"
            title.text = item.title
            meta.text = "${item.status} \u00b7 ${item.category}"
            val colorRes = when (item.status) {
                "Resolved", "Closed" -> R.color.success
                "In Progress" -> R.color.info
                "Pending", "Reopened" -> R.color.accent
                else -> R.color.primary
            }
            meta.setTextColor(ContextCompat.getColor(root.context, colorRes))
            root.setOnClickListener { onClick(item) }
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<SupportTicket>() {
            override fun areItemsTheSame(a: SupportTicket, b: SupportTicket) = a.id == b.id
            override fun areContentsTheSame(a: SupportTicket, b: SupportTicket) = a == b
        }
    }
}
